<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationMember extends Model
{
    protected $fillable = [
        'organization_id',
        'connection_id',
        'network_id',
        'user_id',
        'scope',
        'external_code',
        'email',
        'name',
        'phone',
        'status',
        'sponsor_code',
        'sponsor_member_id',
        'rank_code',
        'rank_name',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(OrganizationVolume::class, 'member_id');
    }

    public function ranks(): HasMany
    {
        return $this->hasMany(OrganizationMemberRank::class, 'member_id');
    }
}
