<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Models\User;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\InventoryAllocation;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\InventoryAlertService;
use App\Modules\Store\Services\ShippingQuoteService;
use App\Modules\Store\Services\StoreTeamMembership;
use App\Shared\Enums\OrderStatus;
use App\Shared\Support\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaceOrderAction
{
    public function __construct(
        private readonly ShippingQuoteService $shipping,
        private readonly InventoryAlertService $alerts,
        private readonly StoreTeamMembership $team,
    ) {}

    /**
     * @param  array{customer_name: string, customer_email?: string|null, items: array<int, array{product_id: int, quantity: int}>, partner_user_id?: int|null, customer_phone?: string|null, payment_method?: string|null, shipping_country?: string|null, shipping_department?: string|null, shipping_area?: string|null, shipping_address?: string|null, channel?: string, delivery?: string, mark_paid?: bool}  $data
     */
    public function handle(Store $store, array $data, ?User $actor = null): Order
    {
        if (! $store->is_active && ($data['channel'] ?? 'ecommerce') !== 'pos') {
            throw ValidationException::withMessages([
                'store' => ['La tienda no está disponible.'],
            ]);
        }

        $store->loadMissing('user');
        $channel = ($data['channel'] ?? 'ecommerce') === 'pos' ? 'pos' : 'ecommerce';
        $partnerId = $this->resolvePartnerId($store, $data, $actor, $channel);

        return DB::transaction(function () use ($store, $data, $partnerId, $channel) {
            $order = Order::create([
                'store_id' => $store->id,
                'network_id' => $store->network_id,
                'partner_user_id' => $partnerId,
                'channel' => $channel,
                'delivery' => ($data['delivery'] ?? null) === 'pickup' ? 'pickup' : 'shipping',
                'payment_method' => $data['payment_method'] ?? null,
                'customer_name' => $data['customer_name'],
                'customer_email' => filled($data['customer_email'] ?? null) ? $data['customer_email'] : '',
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => OrderStatus::Pending,
                'total' => 0,
                'currency' => $store->currency(),
            ]);

            $total = 0;
            $currency = null;

            foreach ($data['items'] as $line) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->lockForUpdate()
                    ->find($line['product_id']);

                if ($product === null || ! $product->is_active || $product->isExpired()) {
                    throw ValidationException::withMessages([
                        'items' => ['Un producto no pertenece a esta tienda o no está a la venta.'],
                    ]);
                }

                if ($channel !== 'pos' && ! $product->is_published) {
                    throw ValidationException::withMessages([
                        'items' => ['Un producto no pertenece a esta tienda o no está a la venta.'],
                    ]);
                }

                if ($product->isIncentiveItem()) {
                    throw ValidationException::withMessages([
                        'items' => ['Los incentivos no se venden solos. Légalos a un producto de inventario.'],
                    ]);
                }

                $qty = (int) $line['quantity'];
                $consumesStock = $product->tracksInventory();
                $lot = null;
                $fromLot = 0;

                if ($partnerId !== null && $consumesStock && $channel !== 'pos') {
                    $lot = InventoryAllocation::query()
                        ->where('store_id', $store->id)
                        ->where('product_id', $product->id)
                        ->where('partner_user_id', $partnerId)
                        ->lockForUpdate()
                        ->first();
                    $fromLot = $lot ? min($qty, $lot->remaining()) : 0;
                }

                $fromWarehouse = $qty - $fromLot;
                $onShelf = $product->isDropship() || $product->stock > 0 || $fromLot > 0;

                if (! $onShelf) {
                    throw ValidationException::withMessages([
                        'items' => ['Un producto no pertenece a esta tienda o no está a la venta.'],
                    ]);
                }

                if ($consumesStock && $product->stock < $fromWarehouse) {
                    throw ValidationException::withMessages([
                        'items' => ['No hay stock suficiente de '.$product->name.'.'],
                    ]);
                }

                $product->loadMissing('incentiveProduct');
                $incentiveNeed = 0;
                $gift = null;

                if ($product->incentive_product_id) {
                    $gift = Product::query()
                        ->where('store_id', $store->id)
                        ->where('source', ProductSource::Incentive)
                        ->lockForUpdate()
                        ->find($product->incentive_product_id);
                    $perSale = max(1, (int) $product->incentive_qty);
                    $incentiveNeed = $perSale * $qty;

                    if ($gift === null) {
                        throw ValidationException::withMessages([
                            'items' => ['El incentivo de '.$product->name.' ya no está en tu inventario.'],
                        ]);
                    }

                    if ($gift->tracksInventory() && $gift->stock < $incentiveNeed) {
                        throw ValidationException::withMessages([
                            'items' => ['No hay stock suficiente del incentivo '.$gift->name.' para vender '.$product->name.'.'],
                        ]);
                    }
                }

                $lineTotal = round(((float) $product->price) * $qty, 2);
                $total += $lineTotal;
                $lineCurrency = Currencies::normalize($product->currency ?: $store->currency(), $store->currency());
                if ($currency === null) {
                    $currency = $lineCurrency;
                } elseif ($currency !== $lineCurrency) {
                    throw ValidationException::withMessages([
                        'items' => ['El pedido debe usar una sola moneda. No mezcles '.$currency.' con '.$lineCurrency.'.'],
                    ]);
                }

                $incentiveCost = $product->incentiveUnitCost();
                $unitCost = $product->unitSaleCost();
                $lineCost = round($unitCost * $qty, 2);
                $lineProfit = round($lineTotal - $lineCost, 2);

                $order->items()->create([
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'unit_cost' => $unitCost,
                    'incentive_cost' => $incentiveCost,
                    'line_total' => $lineTotal,
                    'line_cost' => $lineCost,
                    'line_profit' => $lineProfit,
                ]);

                if ($fromLot > 0 && $lot !== null) {
                    $lot->increment('qty_sold', $fromLot);
                }

                if ($consumesStock && $fromWarehouse > 0) {
                    $product->decrement('stock', $fromWarehouse);
                    $product->refresh();
                    $product->setRelation('store', $store);
                    $this->alerts->notifyLowStock($product);
                }

                if ($gift !== null && $incentiveNeed > 0 && $gift->tracksInventory()) {
                    $gift->decrement('stock', $incentiveNeed);
                    $gift->refresh();
                    $gift->setRelation('store', $store);
                    $this->alerts->notifyLowStock($gift);
                }
            }

            $config = $this->shipping->config($store);
            $pickup = ($data['delivery'] ?? null) === 'pickup';
            $destination = [
                'country' => $data['shipping_country'] ?? null,
                'department' => $data['shipping_department'] ?? null,
                'area' => $data['shipping_area'] ?? null,
            ];

            if ($config['enabled'] && ! $pickup && blank($destination['country'])) {
                throw ValidationException::withMessages([
                    'shipping_country' => ['Indica el país de entrega para calcular el envío.'],
                ]);
            }

            $quote = $pickup || ! $config['enabled']
                ? [
                    'fee' => 0.0,
                    'zone' => $pickup ? 'Retiro' : null,
                    'eta_days' => $pickup ? 0 : null,
                ]
                : $this->shipping->quote($store, $destination, $total);

            $order->forceFill([
                'total' => round($total + $quote['fee'], 2),
                'shipping_fee' => $quote['fee'],
                'shipping_country' => $pickup ? null : ($destination['country'] ? strtoupper((string) $destination['country']) : null),
                'shipping_department' => $pickup ? null : ($destination['department'] ?: null),
                'shipping_area' => $pickup ? null : ($destination['area'] ?: null),
                'shipping_address' => $pickup ? null : ($data['shipping_address'] ?? null),
                'shipping_zone' => $quote['zone'],
                'shipping_eta_days' => $quote['eta_days'],
                'currency' => $currency ?? $store->currency(),
                'delivery' => $pickup ? 'pickup' : 'shipping',
            ])->save();

            if (! empty($data['mark_paid'])) {
                $order->forceFill([
                    'status' => OrderStatus::Paid,
                    'paid_at' => now(),
                ])->save();
            }

            return $order->load(['items', 'partner:id,name,email']);
        });
    }

    /**
     * @param  array{partner_user_id?: int|null}  $data
     */
    private function resolvePartnerId(Store $store, array $data, ?User $actor, string $channel): ?int
    {
        if ($channel === 'pos') {
            if ($actor === null || (int) $actor->id === (int) $store->user_id) {
                return null;
            }

            return $this->team->partnerIdOnTeam($store, $actor->id, $actor);
        }

        return $this->team->partnerIdOnTeam($store, $data['partner_user_id'] ?? null, $actor);
    }
}
