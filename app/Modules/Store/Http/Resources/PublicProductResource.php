<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'source' => $this->source?->value ?? 'personal',
            'store_category_id' => $this->store_category_id,
            'category' => $this->when(
                $this->relationLoaded('category') && $this->category,
                fn () => [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ],
            ),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'technical_sheet' => $this->technical_sheet,
            'price' => $this->price,
            'incentive_qty' => max(1, (int) $this->incentive_qty),
            'incentive' => $this->when(
                $this->relationLoaded('incentiveProduct') && $this->incentiveProduct,
                fn () => [
                    'id' => $this->incentiveProduct->id,
                    'name' => $this->incentiveProduct->name,
                    'image' => $this->incentiveProduct->image,
                    'qty' => max(1, (int) $this->incentive_qty),
                ],
            ),
            'currency' => $this->currency,
            'stock' => $this->stock,
            'image' => $this->image,
            'is_active' => $this->is_active,
            'is_published' => $this->is_published,
            'fulfillment' => $this->fulfillment?->value ?? 'stock',
            'expires_at' => $this->expires_at?->toDateString(),
        ];
    }
}
