<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\InventoryAllocation;

class InventoryAllocationPolicy
{
    public function view(User $user, InventoryAllocation $allocation): bool
    {
        $allocation->loadMissing('store');

        return (int) $user->id === (int) $allocation->store?->user_id;
    }

    public function update(User $user, InventoryAllocation $allocation): bool
    {
        return $this->view($user, $allocation) && $user->can('store.manage');
    }
}
