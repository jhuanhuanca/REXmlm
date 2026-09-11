<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSponsor extends Model
{
    protected $fillable = [
        'organization_id',
        'member_id',
        'sponsor_member_id',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'sponsor_member_id');
    }
}
