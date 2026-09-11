<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Models\User;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\SyncRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationSync extends Model
{
    protected $fillable = [
        'organization_id',
        'connection_id',
        'network_id',
        'user_id',
        'scope',
        'driver',
        'status',
        'records',
        'file_path',
        'file_name',
        'started_at',
        'finished_at',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'scope' => ConnectionScope::class,
            'driver' => ConnectionDriver::class,
            'status' => SyncRunStatus::class,
            'records' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OrganizationConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OrganizationSyncLog::class, 'sync_id');
    }
}
