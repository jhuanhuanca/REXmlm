<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\MLM\Jobs\SendInvitationEmail;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Canonical\LeaderCanonicalSlice;
use App\Modules\Organization\Models\OrganizationMember;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Validation\ValidationException;

class ConvertCompanyPartnerAction
{
    public function __construct(
        private readonly LeaderCanonicalSlice $slice,
        private readonly CreateInvitationAction $createInvitation,
    ) {}

    /**
     * @return array{invitation: Invitation, token: string, member: OrganizationMember}
     */
    public function handle(User $leader, int $memberId): array
    {
        $member = $this->ownedMember($leader, $memberId);
        $email = mb_strtolower(trim((string) $member->email));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => ['Este socio de empresa no tiene correo. Agrégalo antes de convertirlo.'],
            ]);
        }

        if (Referral::query()->where('referrer_id', $leader->id)->whereHas('referred', fn ($q) => $q->where('email', $email))->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Esa persona ya es socio de tu red de plataforma.'],
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ese correo ya tiene cuenta en la plataforma. No se puede volver a invitar.'],
            ]);
        }

        Invitation::query()
            ->where('leader_id', $leader->id)
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending)
            ->update(['status' => InvitationStatus::Revoked]);

        $result = $this->createInvitation->handle($leader, $email);
        SendInvitationEmail::dispatch($result['invitation'], $result['token']);

        $raw = is_array($member->raw) ? $member->raw : [];
        $member->forceFill([
            'raw' => [
                ...$raw,
                'converted_at' => now()->toIso8601String(),
                'invitation_id' => $result['invitation']->id,
            ],
        ])->save();

        return [
            'invitation' => $result['invitation'],
            'token' => $result['token'],
            'member' => $member->fresh(),
        ];
    }

    private function ownedMember(User $leader, int $memberId): OrganizationMember
    {
        $member = $this->slice->membersForLeader($leader)->firstWhere('id', $memberId);

        if (! $member instanceof OrganizationMember) {
            throw ValidationException::withMessages([
                'member' => ['Ese socio de empresa no está en tu red de la marca.'],
            ]);
        }

        return $member;
    }
}
