<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Models;

use App\Modules\MLM\Models\Network;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
