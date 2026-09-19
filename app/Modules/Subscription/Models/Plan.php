<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use Sluggable;

    protected $fillable = [
        'name',
        'slug',
        'price',
        'intro_price',
        'currency',
        'interval',
        'commission_percentage',
        'features',
        'stripe_price_id',
        'paddle_price_id',
        'paddle_intro_discount_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'intro_price' => 'decimal:2',
            'commission_percentage' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function paddlePriceId(): ?string
    {
        $id = trim((string) ($this->paddle_price_id ?: $this->stripe_price_id ?: ''));

        return $id !== '' ? $id : null;
    }

    public function paddleIntroDiscountId(): ?string
    {
        $id = trim((string) ($this->paddle_intro_discount_id ?? ''));

        return $id !== '' ? $id : null;
    }

    public function introPrice(): float
    {
        $value = (float) ($this->intro_price ?? 1);

        return $value > 0 ? $value : 1.0;
    }
}
