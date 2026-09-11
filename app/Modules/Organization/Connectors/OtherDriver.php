<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationSync;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class OtherDriver implements ConnectionDriverContract
{
    public function __construct(
        private readonly SpreadsheetParser $parser = new SpreadsheetParser,
    ) {}

    public function key(): string
    {
        return 'other';
    }

    public function sync(OrganizationConnection $connection, OrganizationSync $sync): SyncOutcome
    {
        $config = $connection->config ?? [];
        $url = (string) ($config['url'] ?? '');
        $notes = (string) ($config['notes'] ?? '');
        $path = $sync->file_path ?: (string) ($config['file_path'] ?? '');
        $name = $sync->file_name ?: (string) ($config['file_name'] ?? 'archivo');
        $hasFile = $path !== '' && Storage::disk('local')->exists($path);

        $logs = [];
        if ($url !== '') {
            $logs[] = ['level' => 'info', 'message' => 'Fuente externa: '.$url];
        }
        if ($notes !== '') {
            $logs[] = ['level' => 'info', 'message' => $notes];
        }

        $payload = null;
        $records = ['other' => 1];

        if ($hasFile) {
            $logs[] = ['level' => 'info', 'message' => 'Archivo adjunto: '.$name];
            try {
                $parsed = $this->parser->parse(Storage::disk('local')->path($path), $name);
                $records = array_merge($records, $parsed['counts']);
                $payload = [
                    'kind' => 'spreadsheet',
                    'headers' => $parsed['headers'],
                    'rows' => $parsed['rows'],
                ];
            } catch (InvalidArgumentException|Throwable $exception) {
                $logs[] = ['level' => 'warning', 'message' => $exception->getMessage()];
            }
        }

        $ok = $url !== '' || $hasFile || $notes !== '';

        return new SyncOutcome(
            $ok,
            $ok ? 'connected' : 'idle',
            $ok ? $records : ['other' => 0],
            $ok ? $logs : [['level' => 'warning', 'message' => 'Indica una URL, notas o un archivo.']],
            $ok
                ? ($payload
                    ? 'Medio leído. Se normaliza a Member / Order / Volume.'
                    : 'Medio registrado. Un archivo tabular se normaliza igual que Excel.')
                : 'Falta URL, archivo o nota.',
            $payload,
        );
    }
}
