<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\MLM\Actions\JoinLeaderNetworkAction;
use App\Modules\MLM\Models\Invitation;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Validation\ValidationException;

class AcceptInvitationAction
{
    public function __construct(
        private readonly JoinLeaderNetworkAction $joinLeaderNetwork,
    ) {}

    public function handle(User $user, string $plainToken): Invitation
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::query()->with('leader')->byPlainToken($plainToken)->first();

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

        $leader = $invitation->leader ?? User::query()->find($invitation->leader_id);

        if ($leader === null) {
            throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no es válida o ya expiró.'],
            ]);
        }

        if ($invitation->catalog_company_id || $invitation->catalog_company_name) {
            $user->forceFill([
                'catalog_company_id' => $user->catalog_company_id ?: $invitation->catalog_company_id,
                'catalog_company_name' => $user->catalog_company_name ?: $invitation->catalog_company_name,
            ])->save();
        }

        $this->joinLeaderNetwork->handle($user, $leader);

        if ($invitation->network_id && (int) $user->current_network_id !== (int) $invitation->network_id) {
            $user->forceFill(['current_network_id' => $invitation->network_id])->save();
        }

        return $invitation;
    }
}
