<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Shared\Enums\ReferralStatus;
use Illuminate\Validation\ValidationException;

class JoinLeaderNetworkAction
{
    public function __construct(
        private readonly SyncUserOrganization $syncOrganization,
    ) {}

    public function handle(User $user, User $leader): void
    {
        if ((int) $user->id === (int) $leader->id) {
            throw ValidationException::withMessages([
                'sponsor_id' => ['No puedes invitarte a ti mismo.'],
            ]);
        }

        if (! $leader->hasRole(config('rexmlm.roles.leader')) || ! $leader->current_network_id) {
            throw ValidationException::withMessages([
                'sponsor_id' => ['El enlace de referido no es válido.'],
            ]);
        }

        $already = Referral::query()->where('referred_id', $user->id)->exists();

        if (! $already) {
            Referral::create([
                'referrer_id' => $leader->id,
                'referred_id' => $user->id,
                'network_id' => $leader->current_network_id,
                'level' => 1,
                'status' => ReferralStatus::Active,
            ]);
        }

        $leader->loadMissing('organization');

        $companyId = $leader->workingCatalogCompanyId() ?: $leader->catalog_company_id;
        $companyName = $leader->catalog_company_name;

        $user->forceFill([
            'sponsor_user_id' => $leader->id,
            'current_network_id' => $leader->current_network_id,
            'country' => $user->country ?: $leader->country,
            'catalog_company_id' => $user->catalog_company_id ?: $companyId,
            'catalog_company_name' => $user->catalog_company_name ?: $companyName,
        ])->save();

        if ($companyId) {
            $organization = $this->syncOrganization->findOrCreate((int) $companyId, $companyName ? (string) $companyName : null);
            $this->syncOrganization->attach($user, $organization);
            OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->where('email', mb_strtolower($user->email))
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);
        } else {
            $this->syncOrganization->handle($user);
        }
    }
}
