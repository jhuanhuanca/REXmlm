<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationDataSource extends Model
{
    protected $fillable = [
        'organization_id',
        'connection_id',
        'kind',
        'label',
        'mapping',
        'is_enabled',
        'last_count',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'is_enabled' => 'boolean',
            'last_count' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OrganizationConnection::class, 'connection_id');
    }
}
