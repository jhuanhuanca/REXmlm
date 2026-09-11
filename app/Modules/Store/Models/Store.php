<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Shared\Support\Currencies;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use Sluggable, SoftDeletes;

    protected $fillable = [
        'user_id',
        'network_id',
        'name',
        'slug',
        'theme',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'catalog_synced_at' => 'datetime',
        ];
    }

    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(StoreProductCategory::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class);
    }

    public function paidOrders(): HasMany
    {
        return $this->orders()->where('status', 'paid');
    }

    public function lowStockBelow(): int
    {
        $value = data_get($this->settings, 'inventory.low_stock_below');

        if ($value === null || $value === '') {
            return max(1, (int) config('rexmlm.inventory.low_stock_below', 3));
        }

        return max(1, min(9999, (int) $value));
    }

    public function expiryWarningDays(): int
    {
        $value = data_get($this->settings, 'inventory.expiry_warning_days');

        if ($value === null || $value === '') {
            return max(1, (int) config('rexmlm.inventory.expiry_warning_days', 15));
        }

        return max(1, min(365, (int) $value));
    }

    public function targetMarginPercent(): ?float
    {
        $value = data_get($this->settings, 'inventory.target_margin_percent');

        if ($value === null || $value === '') {
            return null;
        }

        $margin = round((float) $value, 1);

        if ($margin < 0 || $margin >= 100) {
            return null;
        }

        return $margin;
    }

    public function priceForTargetMargin(float $cost): ?float
    {
        $margin = $this->targetMarginPercent();

        if ($margin === null || $cost <= 0) {
            return null;
        }

        $ratio = $margin / 100;

        if ($ratio >= 1) {
            return null;
        }

        return round($cost / (1 - $ratio), 2);
    }

    public function currency(): string
    {
        $configured = data_get($this->settings, 'currency');
        if (Currencies::isValid(is_string($configured) ? $configured : null)) {
            return Currencies::normalize($configured);
        }

        $this->loadMissing('user:id,country');

        return Currencies::forCountry($this->user?->country);
    }
}
