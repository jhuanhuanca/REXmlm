<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\ConnectionStatus;

class EnsureCatalogConnection
{
    public function handle(Organization $organization, ?User $actor = null): OrganizationConnection
    {
        $existing = OrganizationConnection::query()
            ->where('organization_id', $organization->id)
            ->where('driver', ConnectionDriver::Catalog)
            ->where('scope', ConnectionScope::Organization)
            ->first();

        if ($existing) {
            return $existing;
        }

        return OrganizationConnection::query()->create([
            'organization_id' => $organization->id,
            'scope' => ConnectionScope::Organization,
            'driver' => ConnectionDriver::Catalog,
            'name' => 'Catálogo serv_producmlm',
            'status' => ConnectionStatus::Idle,
            'created_by' => $actor?->id,
        ]);
    }
}
