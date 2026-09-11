<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'catalog_company_id',
        'name',
        'slug',
        'default_timezone',
        'default_currency',
        'status',
        'metrics_profile',
    ];

    protected function casts(): array
    {
        return [
            'catalog_company_id' => 'integer',
            'metrics_profile' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(OrganizationConnection::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(OrganizationVolume::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
