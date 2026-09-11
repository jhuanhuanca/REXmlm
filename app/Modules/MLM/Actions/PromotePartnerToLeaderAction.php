<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Shared\Enums\NetworkStatus;
use App\Shared\Enums\ReferralStatus;
use App\Shared\Enums\TeamCrmStage;
use Illuminate\Support\Str;

class PromotePartnerToLeaderAction
{
    public function __construct(
        private readonly SyncUserOrganization $syncOrganization,
    ) {}

    public function handle(User $user): Network
    {
        if ($user->hasRole(config('rexmlm.roles.leader')) && $user->ownedNetwork()->exists()) {
            return $user->ownedNetwork;
        }

        $network = Network::create([
            'owner_user_id' => $user->id,
            'name' => $user->name,
            'slug' => (Str::slug($user->name) ?: 'user').'-'.$user->id.'-net',
            'status' => NetworkStatus::Active,
        ]);

        $user->syncRoles([config('rexmlm.roles.leader')]);

        $sponsor = $user->sponsor;
        $user->forceFill([
            'current_network_id' => $network->id,
            'catalog_company_id' => $user->catalog_company_id ?: $sponsor?->catalog_company_id,
            'catalog_company_name' => $user->catalog_company_name ?: $sponsor?->catalog_company_name,
            'country' => $user->country ?: $sponsor?->country,
        ])->save();

        $user->store()?->update(['network_id' => $network->id]);
        $user->landingPage()?->update(['network_id' => $network->id]);

        $this->syncOrganization->handle($user);
        app(\App\Modules\Organization\Actions\EnsurePrimaryCompanyMembership::class)->handle($user);

        $user->referralRecord()?->update([
            'status' => ReferralStatus::Independent,
            'crm_stage' => TeamCrmStage::Independent,
        ]);

        return $network;
    }
}
