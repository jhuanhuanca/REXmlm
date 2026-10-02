<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Shared\Exports\WorkbookExport;
use Illuminate\Http\Response;

class StoreReportService
{
    public function inventory(User $user, string $format): Response
    {
        $store = $this->storeOf($user);
        $products = $store->products()
            ->with(['category', 'incentiveProduct'])
            ->where('source', ProductSource::Personal)
            ->orderBy('name')
            ->get();
        $products->each(fn (Product $product) => $product->setRelation('store', $store));
        $personalIds = $products->pluck('id')->all();

        $productRows = $products->map(function (Product $product) {
            return [
                $product->id,
                $product->name,
                $product->category?->name ?? '',
                round((float) $product->price, 2),
                round((float) $product->purchase_cost, 2),
                round((float) $product->incentiveUnitCost(), 2),
                round((float) $product->unitSaleCost(), 2),
                round((float) $product->unitProfit(), 2),
                $product->marginPercent() ?? '',
                $product->incentiveProduct?->name ?? '',
                $product->incentiveProduct ? max(1, (int) $product->incentive_qty) : '',
                (int) $product->stock,
                $product->fulfillment?->value ?? 'stock',
                $product->is_active ? 'Sí' : 'No',
                $product->is_published ? 'Sí' : 'No',
                $product->expires_at?->toDateString() ?? '',
                $product->isExpired() ? 'Vencido' : ($product->isLowStock() ? 'Stock bajo' : ($product->isExpiringSoon() ? 'Por vencer' : 'OK')),
                $product->currency,
                $product->dropship_sku ?? '',
                $product->dropship_url ?? '',
            ];
        })->all();

        [$orderRows, $itemRows, $paid, $pending, $orderCount] = $this->salesTables($store, $personalIds);

        $export = new WorkbookExport(
            'Inventario personal · '.$store->name,
            sprintf('Stock propio y ventas de ese inventario · pagadas %s · pendientes %s', number_format($paid, 2, '.', ''), number_format($pending, 2, '.', '')),
        );
        $export->addSheet('Inventario personal', [
            'ID',
            'Nombre',
            'Categoría',
            'Precio',
            'Costo compra',
            'Costo incentivo',
            'Costo venta',
            'Utilidad unidad',
            'Margen %',
            'Incentivo',
            'Cant. incentivo',
            'Stock',
            'Entrega',
            'Activo',
            'Publicado',
            'Vencimiento',
            'Alerta',
            'Moneda',
            'SKU proveedor',
            'URL proveedor',
        ], $productRows);
        $export->addSheet('Ventas', [
            'ID',
            'Estado',
            'Canal',
            'Entrega',
            'Pago',
            'Cliente',
            'Correo cliente',
            'Teléfono',
            'Socio',
            'Correo socio',
            'Envío',
            'País',
            'Departamento',
            'Zona',
            'Dirección',
            'Total',
            'Moneda',
            'Pagado',
            'Creado',
        ], $orderRows);
        $export->addSheet('Lineas de venta', [
            'Pedido',
            'Estado',
            'Cliente',
            'Socio',
            'Producto',
            'Cantidad',
            'Precio unit.',
            'Costo unit.',
            'Costo incentivo',
            'Total línea',
            'Costo línea',
            'Utilidad línea',
            'Moneda',
            'Fecha',
        ], $itemRows);

        return $export->download('inventario_'.$store->slug.'_'.now()->format('Y-m-d'), $format);
    }

    public function sales(User $user, string $format): Response
    {
        $store = $this->storeOf($user);
        [$orderRows, $itemRows, $paid, $pending, $orderCount] = $this->salesTables($store);

        $export = new WorkbookExport(
            'Ventas · '.$store->name,
            sprintf('Pagadas %s · pendientes %s · %d órdenes', number_format($paid, 2, '.', ''), number_format($pending, 2, '.', ''), $orderCount),
        );
        $export->addSheet('Pedidos', [
            'ID',
            'Estado',
            'Canal',
            'Entrega',
            'Pago',
            'Cliente',
            'Correo cliente',
            'Teléfono',
            'Socio',
            'Correo socio',
            'Envío',
            'País',
            'Departamento',
            'Zona',
            'Dirección',
            'Total',
            'Moneda',
            'Pagado',
            'Creado',
        ], $orderRows);
        $export->addSheet('Lineas', [
            'Pedido',
            'Estado',
            'Cliente',
            'Socio',
            'Producto',
            'Cantidad',
            'Precio unit.',
            'Costo unit.',
            'Costo incentivo',
            'Total línea',
            'Costo línea',
            'Utilidad línea',
            'Moneda',
            'Fecha',
        ], $itemRows);

        return $export->download('ventas_'.$store->slug.'_'.now()->format('Y-m-d'), $format);
    }

    /**
     * @param  list<int>|null  $productIds
     * @return array{0: list<list<scalar|null>>, 1: list<list<scalar|null>>, 2: float, 3: float, 4: int}
     */
    private function salesTables(Store $store, ?array $productIds = null): array
    {
        $orders = $store->orders()
            ->with(['items', 'partner:id,name,email'])
            ->latest()
            ->get();

        $orderRows = [];
        $itemRows = [];
        $paid = 0.0;
        $pending = 0.0;
        $allowed = $productIds === null ? null : array_flip($productIds);

        foreach ($orders as $order) {
            $items = $order->items;
            if ($allowed !== null) {
                $items = $items->filter(fn ($item) => isset($allowed[(int) $item->product_id]));
                if ($items->isEmpty()) {
                    continue;
                }
            }

            $status = $this->orderStatus($order);
            $total = $allowed === null
                ? round((float) $order->total, 2)
                : round((float) $items->sum(fn ($item) => (float) $item->line_total), 2);
            if ($status === 'Pagada') {
                $paid += $total;
            } elseif ($status === 'Pendiente') {
                $pending += $total;
            }

            $orderRows[] = [
                $order->id,
                $status,
                $order->channel === 'pos' ? 'Venta directa' : 'Tienda pública',
                $order->delivery === 'pickup' ? 'Retiro' : 'Envío',
                $this->paymentLabel($order->payment_method),
                $order->customer_name,
                $order->customer_email,
                $order->customer_phone ?? '',
                $order->partner?->name ?? 'Personal',
                $order->partner?->email ?? '',
                round((float) $order->shipping_fee, 2),
                $order->shipping_country ?? '',
                $order->shipping_department ?? '',
                $order->shipping_area ?? '',
                $order->shipping_address ?? '',
                $total,
                $order->currency,
                optional($order->paid_at)?->toDateTimeString() ?? '',
                optional($order->created_at)?->toDateTimeString() ?? '',
            ];

            foreach ($items as $item) {
                $itemRows[] = [
                    $order->id,
                    $status,
                    $order->customer_name,
                    $order->partner?->name ?? 'Personal',
                    $item->name,
                    (int) $item->quantity,
                    round((float) $item->unit_price, 2),
                    round((float) $item->unit_cost, 2),
                    round((float) $item->incentive_cost, 2),
                    round((float) $item->line_total, 2),
                    round((float) $item->line_cost, 2),
                    round((float) $item->line_profit, 2),
                    $order->currency,
                    optional($order->created_at)?->toDateString() ?? '',
                ];
            }
        }

        return [$orderRows, $itemRows, $paid, $pending, count($orderRows)];
    }

    private function storeOf(User $user): Store
    {
        $store = $user->store;
        abort_if($store === null, 404, 'No tienes tienda');

        return $store;
    }

    private function orderStatus(Order $order): string
    {
        $value = is_object($order->status) ? $order->status->value : (string) $order->status;

        return match ($value) {
            'paid' => 'Pagada',
            'cancelled' => 'Cancelada',
            'refunded' => 'Reembolsada',
            default => 'Pendiente',
        };
    }

    private function paymentLabel(?string $method): string
    {
        return match ($method) {
            'qr' => 'QR',
            'qr_binance' => 'Binance',
            'deposit' => 'Depósito',
            'transfer' => 'Transferencia',
            default => $method ?: '',
        };
    }
}
