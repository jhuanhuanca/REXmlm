<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProductCategory extends Model
{
    use Sluggable;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'store_category_id');
    }
}
