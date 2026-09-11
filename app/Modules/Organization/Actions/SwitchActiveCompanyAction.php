<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class SwitchActiveCompanyAction
{
    public function handle(User $user, int $catalogCompanyId): User
    {
        if (! $user->hasCompanyMembership($catalogCompanyId)) {
            throw ValidationException::withMessages([
                'catalog_company_id' => ['No tienes esa empresa en tu cuenta.'],
            ]);
        }

        $user->forceFill(['active_catalog_company_id' => $catalogCompanyId])->save();

        return $user->fresh([
            'roles',
            'store',
            'landingPage',
            'currentNetwork',
            'sponsor.store',
            'sponsor.landingPage',
            'organization',
            'companyMemberships',
        ]) ?? $user;
    }
}
