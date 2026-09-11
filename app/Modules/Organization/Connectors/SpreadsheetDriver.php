<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationSync;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class SpreadsheetDriver implements ConnectionDriverContract
{
    public function __construct(
        private readonly SpreadsheetParser $parser = new SpreadsheetParser,
    ) {}

    public function key(): string
    {
        return 'excel';
    }

    public function sync(OrganizationConnection $connection, OrganizationSync $sync): SyncOutcome
    {
        $path = $sync->file_path ?: (string) (($connection->config ?? [])['file_path'] ?? '');
        $name = $sync->file_name ?: (string) (($connection->config ?? [])['file_name'] ?? 'archivo.csv');

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return new SyncOutcome(false, 'error', [], [
                ['level' => 'error', 'message' => 'No hay archivo para importar.'],
            ], 'Sube un CSV o XLSX.');
        }

        $full = Storage::disk('local')->path($path);

        try {
            $parsed = $this->parser->parse($full, $name);
        } catch (InvalidArgumentException|Throwable $exception) {
            return new SyncOutcome(false, 'error', [], [
                ['level' => 'error', 'message' => $exception->getMessage()],
            ], $exception->getMessage());
        }

        $counts = $parsed['counts'];
        $logs = [
            ['level' => 'info', 'message' => 'Archivo '.$name.': '.$counts['rows'].' filas.'],
            ['level' => 'info', 'message' => 'Columnas: '.implode(', ', $parsed['headers'])],
        ];

        return new SyncOutcome(
            $counts['rows'] > 0,
            $counts['rows'] > 0 ? 'connected' : 'error',
            $counts,
            $logs,
            $counts['rows'] > 0
                ? 'Archivo leído. Se normaliza a Member / Order / Volume.'
                : 'El archivo no tenía filas de datos.',
            [
                'kind' => 'spreadsheet',
                'headers' => $parsed['headers'],
                'rows' => $parsed['rows'],
            ],
        );
    }
}
