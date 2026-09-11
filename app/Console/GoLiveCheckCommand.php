<?php

declare(strict_types=1);

namespace App\Console;

use App\Services\ProductionReadiness;
use Illuminate\Console\Command;

class GoLiveCheckCommand extends Command
{
    protected $signature = 'rexmlm:go-live-check
                            {--strict : Falla también con avisos}
                            {--as-production : Evalúa las reglas de production aunque APP_ENV no lo sea}';

    protected $description = 'Comprueba el checklist de go-live (debug, billing, Redis, CORS, tokens, webhooks)';

    public function handle(ProductionReadiness $readiness): int
    {
        $asProduction = (bool) $this->option('as-production') || $this->laravel->environment('production');
        $issues = $readiness->issues($asProduction);

        if ($issues === []) {
            $this->info('Checklist de configuración: OK.');
            $this->comment('Sigue pendiente en el VPS: worker Horizon, cron schedule:run, backups MySQL/storage y un pedido de prueba.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $row) => [$row['level'], $row['code'], $row['message']], $issues);
        $this->table(['nivel', 'código', 'detalle'], $rows);

        $hasFail = collect($issues)->contains(fn (array $row) => $row['level'] === 'fail');
        $hasWarn = collect($issues)->contains(fn (array $row) => $row['level'] === 'warn');

        if ($hasFail || ($this->option('strict') && $hasWarn)) {
            $this->error('No abras tráfico de cobro hasta resolver los fail.');

            return self::FAILURE;
        }

        $this->warn('Hay avisos. Revisa backups, mail y planes con price id.');

        return self::SUCCESS;
    }
}
