<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Order;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        $order->loadMissing('store');

        return (int) $user->id === (int) $order->store?->user_id;
    }

    public function update(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $user->can('store.manage');
    }
}
