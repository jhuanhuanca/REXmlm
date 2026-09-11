<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationRankDefinition extends Model
{
    protected $fillable = [
        'organization_id',
        'catalog_rank_id',
        'code',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'catalog_rank_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
