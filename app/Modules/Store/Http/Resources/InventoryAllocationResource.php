<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAllocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'product_id' => $this->product_id,
            'partner_user_id' => $this->partner_user_id,
            'qty_assigned' => $this->qty_assigned,
            'qty_sold' => $this->qty_sold,
            'qty_remaining' => $this->remaining(),
            'notes' => $this->notes,
            'product' => $this->when(
                $this->relationLoaded('product') && $this->product,
                fn () => [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'stock' => $this->product->stock,
                    'currency' => $this->product->currency,
                    'price' => $this->product->price,
                ],
            ),
            'partner' => $this->when(
                $this->relationLoaded('partner') && $this->partner,
                fn () => [
                    'id' => $this->partner->id,
                    'name' => $this->partner->name,
                    'email' => $this->partner->email,
                ],
            ),
            'updated_at' => $this->updated_at,
        ];
    }
};
