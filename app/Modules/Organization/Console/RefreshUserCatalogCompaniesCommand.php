<?php

declare(strict_types=1);

namespace App\Modules\Organization\Console;

use App\Modules\Organization\Actions\RefreshUserCatalogCompanyNames;
use Illuminate\Console\Command;

class RefreshUserCatalogCompaniesCommand extends Command
{
    protected $signature = 'catalog:refresh-user-companies';

    protected $description = 'Corrige el nombre de empresa de cada usuario según el catálogo (serv_producmlm), no el texto HGW guardado por error.';

    public function handle(RefreshUserCatalogCompanyNames $action): int
    {
        $updated = $action->handle();
        $this->info("Se actualizaron {$updated} usuarios con su empresa del catálogo.");

        return self::SUCCESS;
    }
}
