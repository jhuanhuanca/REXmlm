<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Modules\Organization\Canonical\PersistCanonicalData;
use App\Modules\Organization\Connectors\DriverRegistry;
use App\Modules\Organization\Connectors\SyncOutcome;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationDataSource;
use App\Modules\Organization\Models\OrganizationSync;
use App\Shared\Enums\ConnectionStatus;
use App\Shared\Enums\SyncRunStatus;
use Throwable;

class RunConnectionSync
{
    public function handle(OrganizationConnection $connection, User $actor, ?string $filePath = null, ?string $fileName = null): OrganizationSync
    {
        $sync = OrganizationSync::query()->create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'network_id' => $connection->network_id,
            'user_id' => $actor->id,
            'scope' => $connection->scope,
            'driver' => $connection->driver,
            'status' => SyncRunStatus::Running,
            'file_path' => $filePath ?: ($connection->config['file_path'] ?? null),
            'file_name' => $fileName ?: ($connection->config['file_name'] ?? null),
            'started_at' => now(),
        ]);

        try {
            $outcome = DriverRegistry::make()->get($connection->driver)->sync($connection, $sync);
            if ($outcome->ok && is_array($outcome->payload) && $outcome->payload !== []) {
                $canonical = app(PersistCanonicalData::class)->handle($connection, $sync, $outcome->payload);
                $outcome = new SyncOutcome(
                    $outcome->ok,
                    $outcome->status,
                    array_merge($outcome->records, $canonical),
                    $outcome->logs,
                    $this->messageAfterCanonical($outcome->message, $canonical),
                    $outcome->payload,
                );
            }
        } catch (Throwable $exception) {
            $outcome = new SyncOutcome(false, 'error', [], [
                ['level' => 'error', 'message' => $exception->getMessage()],
            ], $exception->getMessage());
        }

        foreach ($outcome->logs as $log) {
            $sync->logs()->create([
                'level' => $log['level'] ?? 'info',
                'message' => $log['message'] ?? '',
                'context' => $log['context'] ?? null,
            ]);
        }

        $sync->forceFill([
            'status' => $outcome->ok ? SyncRunStatus::Completed : SyncRunStatus::Failed,
            'records' => $outcome->records,
            'message' => $outcome->message,
            'finished_at' => now(),
        ])->save();

        $connection->forceFill([
            'status' => $outcome->ok ? ConnectionStatus::Connected : ConnectionStatus::Error,
            'last_synced_at' => $outcome->ok ? now() : $connection->last_synced_at,
            'last_error' => $outcome->ok ? null : $outcome->message,
        ])->save();

        foreach ($outcome->records as $kind => $count) {
            if ($kind === 'rows') {
                continue;
            }

            OrganizationDataSource::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'kind' => $kind,
                ],
                [
                    'organization_id' => $connection->organization_id,
                    'label' => $this->labelFor($kind),
                    'last_count' => (int) $count,
                    'is_enabled' => true,
                ],
            );
        }

        return $sync->load('logs');
    }

    private function labelFor(string $kind): string
    {
        return match ($kind) {
            'members' => 'Networkers / miembros',
            'customers' => 'Clientes de empresa',
            'sales' => 'Pedidos de empresa',
            'volumes' => 'Volumen',
            'commissions' => 'Bonos de empresa',
            'products' => 'Productos',
            'ranks' => 'Rangos',
            'catalog' => 'Empresa de catálogo',
            'compensation_plans' => 'Planes de compensación',
            'api' => 'API',
            'other' => 'Otro medio',
            default => $kind,
        };
    }

    /**
     * @param  array<string, int>  $canonical
     */
    private function messageAfterCanonical(?string $message, array $canonical): string
    {
        $members = (int) ($canonical['members'] ?? 0);
        $volumes = (int) ($canonical['volumes'] ?? 0);
        $sales = (int) ($canonical['sales'] ?? 0);
        $summary = "Normalizado: {$members} miembros, {$volumes} volúmenes, {$sales} pedidos.";

        return $message ? $message.' '.$summary : $summary;
    }
}
