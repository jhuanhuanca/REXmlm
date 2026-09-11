<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Store;

class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        return (int) $user->id === (int) $store->user_id || $user->hasRole('admin');
    }

    public function update(User $user, Store $store): bool
    {
        return (int) $user->id === (int) $store->user_id && $user->can('store.manage');
    }
}
