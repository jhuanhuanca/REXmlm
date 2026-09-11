<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Store\Models\InventoryAllocation;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnInventoryAction
{
    /**
     * @param  array{quantity: int}  $data
     */
    public function handle(Store $store, InventoryAllocation $allocation, array $data): ?InventoryAllocation
    {
        if ((int) $allocation->store_id !== (int) $store->id) {
            throw ValidationException::withMessages([
                'allocation' => ['Esa asignación no es de tu tienda.'],
            ]);
        }

        $qty = (int) $data['quantity'];

        return DB::transaction(function () use ($allocation, $qty) {
            $row = InventoryAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $remaining = $row->remaining();

            if ($qty > $remaining) {
                throw ValidationException::withMessages([
                    'quantity' => ['Solo puedes devolver '.$remaining.' unidad'.($remaining === 1 ? '' : 'es').' sin vender.'],
                ]);
            }

            $product = Product::query()->lockForUpdate()->find($row->product_id);
            $row->decrement('qty_assigned', $qty);
            $product?->increment('stock', $qty);
            $row->refresh();

            if ($row->qty_assigned === 0) {
                $row->delete();

                return null;
            }

            return $row->load(['product', 'partner:id,name,email']);
        });
    }
};
