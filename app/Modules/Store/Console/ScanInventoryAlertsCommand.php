<?php

declare(strict_types=1);

namespace App\Modules\Store\Console;

use App\Modules\Store\Services\InventoryAlertService;
use Illuminate\Console\Command;

class ScanInventoryAlertsCommand extends Command
{
    protected $signature = 'inventory:scan-alerts';

    protected $description = 'Genera avisos de stock bajo y productos por vencer';

    public function handle(InventoryAlertService $alerts): int
    {
        $scanned = $alerts->scanAllStores();
        $this->info("Inventario revisado en {$scanned} tiendas.");

        return self::SUCCESS;
    }
}
