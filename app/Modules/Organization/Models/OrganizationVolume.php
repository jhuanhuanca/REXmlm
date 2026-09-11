<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationVolume extends Model
{
    protected $fillable = [
        'organization_id',
        'connection_id',
        'network_id',
        'member_id',
        'scope',
        'period',
        'unit',
        'personal_volume',
        'group_volume',
        'sales_volume',
        'commission_volume',
        'qualifying',
        'priority',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'personal_volume' => 'decimal:4',
            'group_volume' => 'decimal:4',
            'sales_volume' => 'decimal:4',
            'commission_volume' => 'decimal:4',
            'qualifying' => 'boolean',
            'priority' => 'integer',
            'raw' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }

    public function hasVolume(): bool
    {
        return $this->personal_volume !== null
            || $this->group_volume !== null
            || $this->sales_volume !== null
            || $this->commission_volume !== null;
    }
}
