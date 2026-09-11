<?php

declare(strict_types=1);

namespace App\Modules\MLM\Policies;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;

class InvitationPolicy
{
    public function create(User $user): bool
    {
        return $user->can('invitation.create');
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return (int) $user->id === (int) $invitation->leader_id || $user->hasRole('admin');
    }
}
