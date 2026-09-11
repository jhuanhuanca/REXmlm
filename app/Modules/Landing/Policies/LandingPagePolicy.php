<?php

declare(strict_types=1);

namespace App\Modules\Landing\Policies;

use App\Models\User;
use App\Modules\Landing\Models\LandingPage;

class LandingPagePolicy
{
    public function view(User $user, LandingPage $landing): bool
    {
        return (int) $user->id === (int) $landing->user_id || $user->hasRole('admin');
    }

    public function update(User $user, LandingPage $landing): bool
    {
        return (int) $user->id === (int) $landing->user_id && $user->can('landing.manage');
    }
}
