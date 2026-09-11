<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use App\Modules\Organization\Canonical\JsonRecordExtractor;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationSync;
use Illuminate\Support\Facades\Http;
use Throwable;

class ApiDriver implements ConnectionDriverContract
{
    public function __construct(
        private readonly JsonRecordExtractor $extractor = new JsonRecordExtractor,
    ) {}

    public function key(): string
    {
        return 'api';
    }

    public function sync(OrganizationConnection $connection, OrganizationSync $sync): SyncOutcome
    {
        $config = $connection->config ?? [];
        $credentials = $connection->credentials ?? [];
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');

        if ($base === '') {
            return new SyncOutcome(false, 'error', [], [
                ['level' => 'error', 'message' => 'Falta la URL base de la API.'],
            ], 'Configura la URL del backoffice.');
        }

        $health = ltrim((string) ($config['health_path'] ?? ''), '/');
        $url = $health !== '' ? $base.'/'.$health : $base;
        $token = (string) ($credentials['token'] ?? $credentials['api_key'] ?? '');

        $request = Http::acceptJson()->timeout(12)->connectTimeout(5);
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get($url);
        } catch (Throwable $exception) {
            return new SyncOutcome(false, 'error', [], [
                ['level' => 'error', 'message' => $exception->getMessage()],
            ], 'No se pudo conectar con la API.');
        }

        $ok = $response->successful();
        $logs = [[
            'level' => $ok ? 'info' : 'error',
            'message' => 'HTTP '.$response->status().' en '.$url,
            'context' => ['status' => $response->status()],
        ]];

        if (! $ok) {
            return new SyncOutcome(false, 'error', ['api' => 0], $logs, 'La API respondió '.$response->status().'.');
        }

        $rows = $this->extractor->collections($response->json());
        foreach (['members_path', 'orders_path', 'commissions_path', 'volumes_path'] as $key) {
            $path = ltrim((string) ($config[$key] ?? ''), '/');
            if ($path === '') {
                continue;
            }
            try {
                $extra = $request->get($base.'/'.$path);
            } catch (Throwable $exception) {
                $logs[] = ['level' => 'warning', 'message' => $path.': '.$exception->getMessage()];
                continue;
            }
            $logs[] = [
                'level' => $extra->successful() ? 'info' : 'warning',
                'message' => 'HTTP '.$extra->status().' en '.$path,
            ];
            if ($extra->successful()) {
                $rows = array_merge($rows, $this->extractor->collections($extra->json()));
            }
        }

        return new SyncOutcome(
            true,
            'connected',
            [
                'api' => 1,
                'members' => 0,
                'sales' => 0,
                'commissions' => 0,
            ],
            $logs,
            $rows === []
                ? 'API alcanzable. Configura members_path / orders_path o un JSON con miembros para normalizar.'
                : 'API leída. Se normaliza a Member / Order / Volume.',
            $rows === []
                ? ['kind' => 'json', 'body' => $response->json()]
                : ['kind' => 'records', 'rows' => $rows],
        );
    }
}
