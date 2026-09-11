<?php

declare(strict_types=1);

namespace App\Modules\Store\Console;

use App\Modules\Store\Jobs\SyncStoreCatalogJob;
use App\Modules\Store\Models\Store;
use App\Services\Catalog\CompanyCatalogSync;
use Illuminate\Console\Command;

class SyncCompanyCatalogCommand extends Command
{
    protected $signature = 'catalog:sync-stores {--sync : Ejecutar en el proceso en lugar de encolar}';

    protected $description = 'Sincroniza el catálogo de empresa de las tiendas activas (cola low)';

    public function handle(CompanyCatalogSync $sync): int
    {
        $inline = (bool) $this->option('sync');
        $queued = 0;

        Store::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereHas('user', fn ($user) => $user->whereNotNull('catalog_company_id'))
                    ->orWhereHas('user.companyMemberships');
            })
            ->orderBy('id')
            ->chunkById(50, function ($stores) use ($inline, $sync, &$queued): void {
                foreach ($stores as $store) {
                    if ($inline) {
                        $sync->syncStore($store);
                    } else {
                        SyncStoreCatalogJob::dispatch($store->id);
                    }
                    $queued++;
                }
            });

        $this->info($inline
            ? "Catálogo sincronizado en {$queued} tiendas."
            : "Se encolaron {$queued} sincronizaciones de catálogo.");

        return self::SUCCESS;
    }
}
