<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Validation\ValidationException;

class CreateInvitationAction
{
    /**
     * @return array{invitation: Invitation, token: string, resent: bool}
     */
    public function handle(User $leader, string $email): array
    {
        if (! $leader->hasRole(config('rexmlm.roles.leader'))) {
            throw ValidationException::withMessages([
                'email' => ['Solo un líder puede enviar invitaciones.'],
            ]);
        }

        if (! $leader->current_network_id) {
            throw ValidationException::withMessages([
                'email' => ['El líder no tiene una red activa.'],
            ]);
        }

        $email = mb_strtolower(trim($email));

        $companyId = $leader->workingCatalogCompanyId();
        $companyName = $companyId
            ? ($leader->membershipForCompany($companyId)?->catalog_company_name ?: $leader->catalog_company_name)
            : $leader->catalog_company_name;

        $pending = Invitation::query()
            ->where('email', $email)
            ->where('network_id', $leader->current_network_id)
            ->where('status', InvitationStatus::Pending)
            ->first();

        $plainToken = Invitation::generatePlainToken();

        if ($pending) {
            $pending->forceFill([
                'token_hash' => Invitation::hashToken($plainToken),
                'catalog_company_id' => $companyId,
                'catalog_company_name' => $companyName,
                'expires_at' => now()->addDays((int) config('rexmlm.invitation_ttl_days')),
            ])->save();

            return [
                'invitation' => $pending->load('leader:id,name'),
                'token' => $plainToken,
                'resent' => true,
            ];
        }

        $invitation = Invitation::create([
            'leader_id' => $leader->id,
            'network_id' => $leader->current_network_id,
            'catalog_company_id' => $companyId,
            'catalog_company_name' => $companyName,
            'email' => $email,
            'token_hash' => Invitation::hashToken($plainToken),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays((int) config('rexmlm.invitation_ttl_days')),
        ]);

        return [
            'invitation' => $invitation->load('leader:id,name'),
            'token' => $plainToken,
            'resent' => false,
        ];
    }
}
