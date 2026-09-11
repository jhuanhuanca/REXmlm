<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\ConnectionStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UpsertOrganizationConnection
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Organization $organization, User $actor, array $payload, ?UploadedFile $file = null): OrganizationConnection
    {
        $driver = ConnectionDriver::from((string) $payload['driver']);
        $scope = ConnectionScope::from((string) ($payload['scope'] ?? ConnectionScope::Organization->value));

        if ($scope === ConnectionScope::Network && $actor->hasRole('admin') === false) {
            $payload['network_id'] = $actor->ownedNetwork?->id ?? $actor->current_network_id;
            $payload['user_id'] = $actor->id;
            if (! $payload['network_id']) {
                throw ValidationException::withMessages([
                    'network' => ['Necesitas una red propia para importar este archivo.'],
                ]);
            }
        }

        $connection = null;
        if (! empty($payload['id'])) {
            $connection = OrganizationConnection::query()
                ->where('organization_id', $organization->id)
                ->find((int) $payload['id']);
        }

        if ($connection === null && $driver === ConnectionDriver::Catalog && $scope === ConnectionScope::Organization) {
            $connection = OrganizationConnection::query()
                ->where('organization_id', $organization->id)
                ->where('driver', ConnectionDriver::Catalog)
                ->where('scope', ConnectionScope::Organization)
                ->first();
        }

        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $credentials = is_array($payload['credentials'] ?? null) ? $payload['credentials'] : [];

        if ($file) {
            $stored = $file->store('connections/'.$organization->id, 'local');
            $config['file_path'] = $stored;
            $config['file_name'] = $file->getClientOriginalName();
        }

        if ($connection === null) {
            $connection = new OrganizationConnection([
                'organization_id' => $organization->id,
                'created_by' => $actor->id,
            ]);
        }

        $existingCredentials = $connection->exists ? ($connection->credentials ?? []) : [];
        if ($credentials === []) {
            $credentials = $existingCredentials;
        } else {
            $credentials = array_filter([...$existingCredentials, ...$credentials], fn ($value) => $value !== null && $value !== '');
        }

        $connection->fill([
            'network_id' => $payload['network_id'] ?? $connection->network_id,
            'user_id' => $payload['user_id'] ?? $connection->user_id,
            'scope' => $scope,
            'driver' => $driver,
            'name' => (string) ($payload['name'] ?? $this->defaultName($driver, $scope)),
            'status' => $connection->status ?? ConnectionStatus::Idle,
            'config' => array_filter([...($connection->config ?? []), ...$config], fn ($value) => $value !== null && $value !== ''),
            'credentials' => $credentials === [] ? null : $credentials,
        ]);
        $connection->save();

        return $connection->fresh() ?? $connection;
    }

    private function defaultName(ConnectionDriver $driver, ConnectionScope $scope): string
    {
        $names = [
            ConnectionDriver::Api->value => 'API del backoffice',
            ConnectionDriver::Excel->value => $scope === ConnectionScope::Network ? 'Excel de mi red' : 'Excel de la empresa',
            ConnectionDriver::Catalog->value => 'Catálogo serv_producmlm',
            ConnectionDriver::Other->value => 'Otro medio',
        ];

        return $names[$driver->value] ?? $driver->value;
    }
}
