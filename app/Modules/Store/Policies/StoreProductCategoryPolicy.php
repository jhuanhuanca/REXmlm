<?php

declare(strict_types=1);

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\StoreProductCategory;

class StoreProductCategoryPolicy
{
    public function create(User $user): bool
    {
        return $user->can('store.manage') && $user->store !== null;
    }

    public function update(User $user, StoreProductCategory $category): bool
    {
        $category->loadMissing('store');

        return (int) $user->id === (int) $category->store?->user_id && $user->can('store.manage');
    }

    public function delete(User $user, StoreProductCategory $category): bool
    {
        return $this->update($user, $category);
    }
}
