<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSyncLog extends Model
{
    protected $fillable = [
        'sync_id',
        'level',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(OrganizationSync::class, 'sync_id');
    }
}
