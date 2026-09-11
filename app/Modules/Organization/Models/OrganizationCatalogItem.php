<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationCatalogItem extends Model
{
    protected $fillable = [
        'organization_id',
        'catalog_product_id',
        'sku',
        'name',
        'pv',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'catalog_product_id' => 'integer',
            'pv' => 'decimal:4',
            'raw' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
