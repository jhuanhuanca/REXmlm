<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Product extends Model
{
    use Sluggable, SoftDeletes;

    protected $fillable = [
        'store_id',
        'store_category_id',
        'catalog_company_id',
        'catalog_product_id',
        'source',
        'name',
        'slug',
        'description',
        'technical_sheet',
        'price',
        'purchase_cost',
        'incentive_product_id',
        'incentive_qty',
        'currency',
        'stock',
        'image',
        'dropship_url',
        'dropship_sku',
        'is_active',
        'is_published',
        'fulfillment',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'purchase_cost' => 'decimal:2',
            'incentive_qty' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_published' => 'boolean',
            'expires_at' => 'date',
            'source' => ProductSource::class,
            'fulfillment' => ProductFulfillment::class,
        ];
    }

    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
                'unique' => true,
            ],
        ];
    }

    public function scopeWithUniqueSlugConstraints(Builder $query, Model $model, string $attribute, array $config, string $slug): Builder
    {
        return $query->where('store_id', $model->getAttribute('store_id'));
    }

    public function scopeListedForSale(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('is_published', true)
            ->where(function (Builder $inner) {
                $inner->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', Carbon::today()->toDateString());
            })
            ->where(function (Builder $inner) {
                $inner->where('fulfillment', ProductFulfillment::Dropship)
                    ->orWhere('stock', '>', 0);
            })
            ->where('source', '!=', ProductSource::Incentive);
    }

    public function isCompanyItem(): bool
    {
        return $this->source === ProductSource::Company || $this->catalog_product_id !== null;
    }

    public function isDropship(): bool
    {
        return $this->fulfillment === ProductFulfillment::Dropship;
    }

    public function tracksInventory(): bool
    {
        return ! $this->isDropship() || $this->stock > 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(Carbon::today());
    }

    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) Carbon::today()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false);
    }

    public function isLowStock(): bool
    {
        if (! $this->tracksInventory()) {
            return false;
        }

        return $this->stock <= $this->lowStockBelow();
    }

    public function isExpiringSoon(): bool
    {
        $days = $this->daysUntilExpiry();

        if ($days === null) {
            return false;
        }

        return $days >= 0 && $days <= $this->expiryWarningDays();
    }

    public function lowStockBelow(): int
    {
        $this->loadMissing('store');

        return $this->store?->lowStockBelow()
            ?? max(1, (int) config('rexmlm.inventory.low_stock_below', 3));
    }

    public function expiryWarningDays(): int
    {
        $this->loadMissing('store');

        return $this->store?->expiryWarningDays()
            ?? max(1, (int) config('rexmlm.inventory.expiry_warning_days', 15));
    }

    public function isIncentiveItem(): bool
    {
        return $this->source === ProductSource::Incentive;
    }

    public function purchaseCost(): float
    {
        return round((float) $this->purchase_cost, 2);
    }

    public function incentiveUnitCost(): float
    {
        if ($this->incentive_product_id === null) {
            return 0.0;
        }

        $this->loadMissing('incentiveProduct');
        $qty = max(1, (int) $this->incentive_qty);

        return round(((float) ($this->incentiveProduct?->purchase_cost ?? 0)) * $qty, 2);
    }

    public function unitSaleCost(): float
    {
        return round($this->purchaseCost() + $this->incentiveUnitCost(), 2);
    }

    public function unitProfit(): float
    {
        return round((float) $this->price - $this->unitSaleCost(), 2);
    }

    public function marginPercent(): ?float
    {
        $price = (float) $this->price;

        if ($price <= 0) {
            return null;
        }

        return round(($this->unitProfit() / $price) * 100, 1);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreProductCategory::class, 'store_category_id');
    }

    public function incentiveProduct(): BelongsTo
    {
        return $this->belongsTo(self::class, 'incentive_product_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class);
    }
}
