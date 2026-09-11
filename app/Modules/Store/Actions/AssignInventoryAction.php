<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\InventoryAllocation;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\InventoryAlertService;
use App\Modules\Store\Services\StoreTeamMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignInventoryAction
{
    public function __construct(
        private readonly StoreTeamMembership $team,
        private readonly InventoryAlertService $alerts,
    ) {}

    /**
     * @param  array{product_id: int, partner_user_id: int, quantity: int, notes?: string|null}  $data
     */
    public function handle(Store $store, array $data): InventoryAllocation
    {
        $qty = (int) $data['quantity'];
        $partnerId = $this->team->partnerIdOnTeam($store, $data['partner_user_id']);

        if ($partnerId === null) {
            throw ValidationException::withMessages([
                'partner_user_id' => ['Esa persona no está en tu equipo o aún no tiene cuenta en la plataforma.'],
            ]);
        }

        return DB::transaction(function () use ($store, $data, $qty, $partnerId) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->find($data['product_id']);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no pertenece a tu inventario.'],
                ]);
            }

            if ($product->source !== ProductSource::Personal || ! $product->tracksInventory()) {
                throw ValidationException::withMessages([
                    'product_id' => ['Solo puedes asignar productos de tu inventario personal con stock.'],
                ]);
            }

            if ($product->stock < $qty) {
                throw ValidationException::withMessages([
                    'quantity' => ['No hay suficientes unidades en bodega ('.$product->stock.').'],
                ]);
            }

            $product->decrement('stock', $qty);
            $product->refresh();
            $product->setRelation('store', $store);
            $this->alerts->notifyLowStock($product);

            $allocation = InventoryAllocation::query()->firstOrNew([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'partner_user_id' => $partnerId,
            ]);
            $allocation->qty_assigned = (int) $allocation->qty_assigned + $qty;
            $allocation->qty_sold = (int) $allocation->qty_sold;
            if (array_key_exists('notes', $data)) {
                $allocation->notes = $data['notes'];
            }
            $allocation->save();

            return $allocation->fresh(['product', 'partner:id,name,email']);
        });
    }
};
