<?php

declare(strict_types=1);

namespace App\Modules\MLM\Actions;

use App\Models\User;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationSponsor;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterCompanyPartnerAction
{
    public function __construct(
        private readonly SyncUserOrganization $syncOrganization,
    ) {}

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, code?: string|null, rank_name?: string|null}  $data
     */
    public function handle(User $leader, array $data): OrganizationMember
    {
        if (! $leader->hasRole(config('rexmlm.roles.leader'))) {
            throw ValidationException::withMessages([
                'name' => ['Solo un líder puede registrar socios de empresa.'],
            ]);
        }

        $organization = $leader->workingOrganization();
        if ($organization === null) {
            $organization = $this->syncOrganization->handle($leader);
            $organization = $leader->fresh()?->workingOrganization() ?? $organization;
        }

        if ($organization === null) {
            throw ValidationException::withMessages([
                'name' => ['Primero elige tu empresa de catálogo. El socio de empresa vive en el plano de la marca, no en la plataforma.'],
            ]);
        }

        $email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
        if ($email) {
            $taken = OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'email' => ['Ese correo ya está en los socios de empresa.'],
                ]);
            }
        }

        $leaderMember = $this->ensureLeaderMember($leader, $organization->id);
        $code = filled($data['code'] ?? null)
            ? trim((string) $data['code'])
            : 'MAN-'.$leader->id.'-'.Str::upper(Str::random(6));

        if (OrganizationMember::query()->where('organization_id', $organization->id)->where('external_code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Ese código ya existe en la empresa.'],
            ]);
        }

        $member = OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'network_id' => $leader->ownedNetwork?->id ?? $leader->current_network_id,
            'scope' => ConnectionScope::Network->value,
            'external_code' => $code,
            'email' => $email,
            'name' => trim((string) $data['name']),
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'status' => 'active',
            'sponsor_code' => $leaderMember->external_code,
            'sponsor_member_id' => $leaderMember->id,
            'rank_name' => filled($data['rank_name'] ?? null) ? trim((string) $data['rank_name']) : null,
            'raw' => ['source' => 'manual'],
        ]);

        OrganizationSponsor::query()->updateOrCreate(
            ['member_id' => $member->id],
            [
                'organization_id' => $organization->id,
                'sponsor_member_id' => $leaderMember->id,
            ],
        );

        return $member->fresh();
    }

    private function ensureLeaderMember(User $leader, int $organizationId): OrganizationMember
    {
        $existing = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($leader) {
                $query->where('user_id', $leader->id)
                    ->orWhere('email', mb_strtolower((string) $leader->email));
            })
            ->first();

        if ($existing) {
            if (! $existing->user_id) {
                $existing->forceFill(['user_id' => $leader->id])->save();
            }

            return $existing;
        }

        return OrganizationMember::query()->create([
            'organization_id' => $organizationId,
            'network_id' => $leader->ownedNetwork?->id ?? $leader->current_network_id,
            'user_id' => $leader->id,
            'scope' => ConnectionScope::Network->value,
            'external_code' => 'L-'.$leader->id,
            'email' => mb_strtolower((string) $leader->email),
            'name' => $leader->name,
            'status' => 'active',
            'raw' => ['source' => 'leader_root'],
        ]);
    }
}
