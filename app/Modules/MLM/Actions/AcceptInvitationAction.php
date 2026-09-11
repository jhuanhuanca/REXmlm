<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Shared\Enums\InvitationStatus;
use App\Shared\Enums\ReferralStatus;
use Illuminate\Validation\ValidationException;

class AcceptInvitationAction
{
    public function __construct(
        private readonly SyncUserOrganization $syncOrganization,
    ) {}

    public function handle(User $user, string $plainToken): Invitation
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()->byPlainToken($plainToken)->first();

        if ($invitation === null || ! $invitation->isUsable()) {
            throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no es válida o ya expiró.'],
            ]);
        }

        if (strcasecmp($invitation->email, $user->email) !== 0) {
            throw ValidationException::withMessages([
                'email' => ['El email no coincide con la invitación.'],
            ]);
        }

        $invitation->forceFill([
            'status' => InvitationStatus::Accepted,
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();

        Referral::create([
            'referrer_id' => $invitation->leader_id,
            'referred_id' => $user->id,
            'network_id' => $invitation->network_id,
            'level' => 1,
            'status' => ReferralStatus::Active,
        ]);

        $leader = $invitation->relationLoaded('leader')
            ? $invitation->leader
            : User::query()->with('organization')->find($invitation->leader_id);
        $leader?->loadMissing('organization');

        $companyId = $invitation->catalog_company_id ?: $leader?->workingCatalogCompanyId() ?: $leader?->catalog_company_id;
        $companyName = $invitation->catalog_company_name ?: $leader?->catalog_company_name;

        $user->forceFill([
            'sponsor_user_id' => $invitation->leader_id,
            'current_network_id' => $invitation->network_id,
            'country' => $user->country ?: $leader?->country,
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

        return $invitation;
    }
}
