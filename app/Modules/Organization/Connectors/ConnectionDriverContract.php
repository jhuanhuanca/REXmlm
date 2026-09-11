<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationSync;

interface ConnectionDriverContract
{
    public function key(): string;

    public function sync(OrganizationConnection $connection, OrganizationSync $sync): SyncOutcome;
}
