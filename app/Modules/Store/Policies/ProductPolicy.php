<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Product;

class ProductPolicy
{
    public function create(User $user): bool
    {
        return $user->can('product.manage') && $user->store !== null;
    }

    public function view(User $user, Product $product): bool
    {
        $product->loadMissing('store');

        return (int) $user->id === (int) $product->store?->user_id || $user->hasRole('admin');
    }

    public function update(User $user, Product $product): bool
    {
        $product->loadMissing('store');

        return (int) $user->id === (int) $product->store?->user_id && $user->can('product.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }
}
