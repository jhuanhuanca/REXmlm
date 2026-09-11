<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationOrderItem extends Model
{
    protected $fillable = [
        'organization_id',
        'order_id',
        'sku',
        'name',
        'quantity',
        'pv',
        'amount',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'pv' => 'decimal:4',
            'amount' => 'decimal:2',
            'raw' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrganizationOrder::class, 'order_id');
    }
}
