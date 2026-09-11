<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationOrder extends Model
{
    protected $fillable = [
        'organization_id',
        'connection_id',
        'network_id',
        'member_id',
        'customer_id',
        'scope',
        'external_code',
        'period',
        'ordered_at',
        'total',
        'currency',
        'qualifying',
        'status',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'total' => 'decimal:2',
            'qualifying' => 'boolean',
            'raw' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrganizationOrderItem::class, 'order_id');
    }
}
