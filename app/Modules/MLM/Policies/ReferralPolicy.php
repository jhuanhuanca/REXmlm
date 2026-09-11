<?php

declare(strict_types=1);

namespace App\Modules\MLM\Policies;

use App\Models\User;
use App\Modules\MLM\Models\Referral;

class ReferralPolicy
{
    public function view(User $user, Referral $referral): bool
    {
        return (int) $user->id === (int) $referral->referrer_id;
    }

    public function update(User $user, Referral $referral): bool
    {
        return $this->view($user, $referral) && $user->can('team.view');
    }
}
