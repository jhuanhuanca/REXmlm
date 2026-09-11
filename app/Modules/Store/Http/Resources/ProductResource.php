<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Store\Models\InventoryAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'catalog_company_id' => $this->catalog_company_id,
            'catalog_product_id' => $this->catalog_product_id,
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
            'purchase_cost' => $this->purchase_cost,
            'incentive_product_id' => $this->incentive_product_id,
            'incentive_qty' => max(1, (int) $this->incentive_qty),
            'incentive_cost' => $this->incentiveUnitCost(),
            'unit_cost' => $this->unitSaleCost(),
            'unit_profit' => $this->unitProfit(),
            'margin_percent' => $this->marginPercent(),
            'incentive' => $this->when(
                $this->relationLoaded('incentiveProduct') && $this->incentiveProduct,
                fn () => [
                    'id' => $this->incentiveProduct->id,
                    'name' => $this->incentiveProduct->name,
                    'image' => $this->incentiveProduct->image,
                    'qty' => max(1, (int) $this->incentive_qty),
                    'purchase_cost' => $this->incentiveProduct->purchase_cost,
                    'stock' => $this->incentiveProduct->stock,
                ],
            ),
            'currency' => $this->currency,
            'stock' => $this->stock,
            'image' => $this->image,
            'is_active' => $this->is_active,
            'is_published' => $this->is_published,
            'fulfillment' => $this->fulfillment?->value ?? 'stock',
            'expires_at' => $this->expires_at?->toDateString(),
            'dropship_url' => $this->dropship_url,
            'dropship_sku' => $this->dropship_sku,
            'is_expired' => $this->isExpired(),
            'is_low_stock' => $this->isLowStock(),
            'is_expiring_soon' => $this->isExpiringSoon(),
            'days_until_expiry' => $this->daysUntilExpiry(),
            'allocated_remaining' => $this->when(
                $this->relationLoaded('allocations'),
                fn () => $this->allocations->sum(fn (InventoryAllocation $row) => $row->remaining()),
            ),
        ];
    }
}
