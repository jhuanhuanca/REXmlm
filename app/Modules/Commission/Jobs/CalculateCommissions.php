<?php

declare(strict_types=1);

namespace App\Modules\Commission\Jobs;

use App\Models\User;
use App\Modules\Commission\Actions\AccrueReferralSubscriptionCommission;
use App\Modules\Subscription\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CalculateCommissions implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
        public Subscription $subscription,
    ) {
        $this->onQueue('high');
    }

    public function handle(AccrueReferralSubscriptionCommission $accrue): void
    {
        $this->subscription->loadMissing('plan');
        $plan = $this->subscription->plan;

        if ($plan === null) {
            return;
        }

        $accrue->handle($this->user, $plan, $this->subscription);
    }
}
