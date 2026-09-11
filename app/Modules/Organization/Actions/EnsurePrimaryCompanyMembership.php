<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Modules\Organization\Models\UserCompanyMembership;

class EnsurePrimaryCompanyMembership
{
    public function handle(User $user): void
    {
        if (! $user->catalog_company_id) {
            return;
        }

        $companyId = (int) $user->catalog_company_id;

        $membership = UserCompanyMembership::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'catalog_company_id' => $companyId,
            ],
            [
                'catalog_company_name' => $user->catalog_company_name,
                'catalog_rank_id' => $user->catalog_rank_id,
                'catalog_rank_name' => $user->catalog_rank_name,
                'is_primary' => true,
            ],
        );

        if (! $membership->is_primary && ! $user->companyMemberships()->where('is_primary', true)->exists()) {
            $membership->forceFill(['is_primary' => true])->save();
        }

        if (! $user->active_catalog_company_id) {
            $user->forceFill(['active_catalog_company_id' => $companyId])->save();
        }
    }
}
