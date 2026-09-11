<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationConnection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'network_id',
        'user_id',
        'scope',
        'driver',
        'name',
        'status',
        'config',
        'credentials',
        'last_synced_at',
        'last_error',
        'created_by',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'scope' => ConnectionScope::class,
            'driver' => ConnectionDriver::class,
            'status' => ConnectionStatus::class,
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(OrganizationDataSource::class, 'connection_id');
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(OrganizationSync::class, 'connection_id')->latest();
    }

    public function hasToken(): bool
    {
        $credentials = $this->credentials ?? [];

        return filled($credentials['token'] ?? null) || filled($credentials['api_key'] ?? null);
    }
}
