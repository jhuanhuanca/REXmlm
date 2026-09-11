<?php

declare(strict_types=1);

namespace App\Shared\Models\Concerns;

use App\Modules\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para tablas nuevas del Centro de Cierre. No se aplica a orders/commissions v1.
 *
 * @method static Builder<static> forOrganization(int $organizationId)
 */
trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where($this->qualifyColumn('organization_id'), $organizationId);
    }
}
