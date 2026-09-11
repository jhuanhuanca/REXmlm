<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationCustomer extends Model
{
    protected $fillable = [
        'organization_id',
        'member_id',
        'network_id',
        'external_code',
        'email',
        'name',
        'raw',
    ];

    protected function casts(): array
    {
        return [
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
}
