<?php

declare(strict_types=1);

namespace App\Modules\Organization\Canonical;

use App\Models\User;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Models\OrganizationCompanyCommission;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationMemberRank;
use App\Modules\Organization\Models\OrganizationOrder;
use App\Modules\Organization\Models\OrganizationSponsor;
use App\Modules\Organization\Models\OrganizationVolume;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Support\Collection;

class LeaderCanonicalSlice
{
    /**
     * Volumen de empresa para la red de un líder. Nunca inventa PV: si no hay
     * filas, `available` es false (no cero).
     *
     * @return array<string, mixed>
     */
    public function for(User $user, string $period): array
    {
        $empty = [
            'available' => false,
            'source' => null,
            'unit' => null,
            'personal' => null,
            'group' => null,
            'sales_volume' => null,
            'commission_volume' => null,
            'members' => 0,
            'orders' => 0,
            'bonuses' => null,
            'rank' => null,
        ];

        $organizationId = $user->workingOrganizationId();
        if (! $organizationId) {
            return $empty;
        }

        $memberIds = $this->memberIdsForLeader($user);
        if ($memberIds->isEmpty()) {
            return $empty;
        }

        $volumes = OrganizationVolume::query()
            ->where('organization_id', $organizationId)
            ->where('period', $period)
            ->whereIn('member_id', $memberIds)
            ->orderByDesc('priority')
            ->get()
            ->groupBy('member_id')
            ->map(fn (Collection $rows) => $rows->first());

        $leaderMember = $this->leaderMember($user, $memberIds);
        $leaderVolume = $leaderMember ? $volumes->get($leaderMember->id) : null;

        $hasVolume = $volumes->contains(fn ($row) => $row instanceof OrganizationVolume && $row->hasVolume());
        $scopes = $volumes->map(fn ($row) => $row?->scope)->filter()->unique();
        $source = null;
        if ($scopes->count() > 1) {
            $source = 'mixed';
        } elseif ($scopes->contains(ConnectionScope::Organization->value)) {
            $source = 'organization';
        } elseif ($scopes->contains(ConnectionScope::Network->value)) {
            $source = 'network';
        }

        $bonusTotal = OrganizationCompanyCommission::query()
            ->where('organization_id', $organizationId)
            ->where('period', $period)
            ->whereIn('member_id', $memberIds)
            ->sum('amount');

        $orders = OrganizationOrder::query()
            ->where('organization_id', $organizationId)
            ->where('period', $period)
            ->whereIn('member_id', $memberIds)
            ->count();

        $rank = null;
        if ($leaderMember) {
            $snapshot = OrganizationMemberRank::query()
                ->where('member_id', $leaderMember->id)
                ->where('period', $period)
                ->orderByRaw("CASE scope WHEN 'organization' THEN 0 ELSE 1 END")
                ->first()
                ?? $leaderMember;
            $rankName = $snapshot instanceof OrganizationMemberRank
                ? $snapshot->rank_name
                : $leaderMember->rank_name;
            $rankCode = $snapshot instanceof OrganizationMemberRank
                ? $snapshot->rank_code
                : $leaderMember->rank_code;
            if ($rankName || $rankCode) {
                $rank = [
                    'code' => $rankCode,
                    'name' => $rankName,
                ];
            }
        }

        $personal = $leaderVolume?->personal_volume;
        $group = $leaderVolume?->group_volume;

        return [
            'available' => $hasVolume,
            'source' => $hasVolume ? $source : null,
            'unit' => $hasVolume ? ($leaderVolume?->unit ?? $volumes->first()?->unit) : null,
            'personal' => $personal !== null ? (float) $personal : null,
            'group' => $group !== null ? (float) $group : null,
            'sales_volume' => $leaderVolume?->sales_volume !== null ? (float) $leaderVolume->sales_volume : null,
            'commission_volume' => $leaderVolume?->commission_volume !== null ? (float) $leaderVolume->commission_volume : null,
            'members' => $memberIds->count(),
            'orders' => $orders,
            'bonuses' => $bonusTotal > 0 ? round((float) $bonusTotal, 2) : null,
            'rank' => $rank,
        ];
    }

    /**
     * @return Collection<int, int>
     */
    public function memberIdsForLeader(User $user): Collection
    {
        $user->loadMissing(['ownedNetwork', 'organization', 'companyMemberships']);
        $organizationId = (int) ($user->workingOrganizationId() ?? 0);
        if ($organizationId <= 0) {
            return collect();
        }
        $networkId = $user->ownedNetwork?->id ?? $user->current_network_id;

        $partnerIds = Referral::query()
            ->where('referrer_id', $user->id)
            ->pluck('referred_id')
            ->push($user->id)
            ->unique()
            ->filter()
            ->values();

        $emails = User::query()
            ->whereIn('id', $partnerIds)
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower((string) $email))
            ->filter()
            ->values();

        $roots = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($user, $partnerIds, $emails, $networkId) {
                $query->where('user_id', $user->id)
                    ->orWhereIn('user_id', $partnerIds);
                if ($emails->isNotEmpty()) {
                    $query->orWhereIn('email', $emails);
                }
                if ($networkId) {
                    $query->orWhere('network_id', $networkId);
                }
            })
            ->pluck('id');

        return $this->expandDownline($organizationId, $roots);
    }

    /**
     * Afiliados de empresa visibles para el líder (su downline canónica).
     * No incluye al propio líder.
     *
     * @return Collection<int, OrganizationMember>
     */
    public function membersForLeader(User $user): Collection
    {
        $ids = $this->memberIdsForLeader($user);
        if ($ids->isEmpty() || ! $user->workingOrganizationId()) {
            return collect();
        }

        $leaderMember = $this->leaderMember($user, $ids);
        $organizationId = (int) $user->workingOrganizationId();

        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->when($leaderMember, fn ($query) => $query->where('id', '!=', $leaderMember->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, int>  $roots
     * @return Collection<int, int>
     */
    private function expandDownline(int $organizationId, Collection $roots): Collection
    {
        $ids = $roots->unique()->values();
        for ($i = 0; $i < 40; $i++) {
            $children = OrganizationSponsor::query()
                ->where('organization_id', $organizationId)
                ->whereIn('sponsor_member_id', $ids)
                ->pluck('member_id');
            $next = $ids->merge($children)->unique()->values();
            if ($next->count() === $ids->count()) {
                break;
            }
            $ids = $next;
        }

        return $ids;
    }

    /**
     * @param  Collection<int, int>  $memberIds
     */
    private function leaderMember(User $user, Collection $memberIds): ?OrganizationMember
    {
        return OrganizationMember::query()
            ->whereIn('id', $memberIds)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('email', mb_strtolower((string) $user->email));
            })
            ->orderByRaw("CASE scope WHEN 'organization' THEN 0 ELSE 1 END")
            ->first();
    }
}
