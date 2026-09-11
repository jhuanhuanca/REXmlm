<?php

declare(strict_types=1);

namespace App\Modules\Store\Jobs;

use App\Modules\Store\Models\Store;
use App\Services\Catalog\CompanyCatalogSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncStoreCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public int $storeId,
    ) {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(CompanyCatalogSync $sync): void
    {
        $store = Store::query()->find($this->storeId);

        if ($store === null) {
            return;
        }

        $sync->syncStore($store);
    }
}
