<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationCompanyCommission extends Model
{
    protected $fillable = [
        'organization_id',
        'connection_id',
        'network_id',
        'member_id',
        'scope',
        'period',
        'external_code',
        'kind',
        'amount',
        'currency',
        'source_type',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }
}
