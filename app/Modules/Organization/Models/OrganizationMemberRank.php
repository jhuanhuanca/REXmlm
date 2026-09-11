<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMemberRank extends Model
{
    protected $fillable = [
        'organization_id',
        'member_id',
        'scope',
        'period',
        'rank_code',
        'rank_name',
        'qualified',
    ];

    protected function casts(): array
    {
        return [
            'qualified' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }
}
