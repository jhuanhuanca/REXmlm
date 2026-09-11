<?php

declare(strict_types=1);

namespace App\Modules\Commission\Policies;

use App\Models\User;
use App\Modules\Commission\Models\WithdrawalRequest;

class WithdrawalRequestPolicy
{
    public function view(User $user, WithdrawalRequest $withdrawal): bool
    {
        return (int) $user->id === (int) $withdrawal->user_id || $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->can('commission.view');
    }
}
