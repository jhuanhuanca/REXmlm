<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Canonical\LeaderCanonicalSlice;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\ReferralStatus;

class StoreTeamMembership
{
    public function __construct(
        private readonly LeaderCanonicalSlice $slice,
    ) {}

    public function partnerIdOnTeam(Store $store, mixed $requestedId, ?User $actor = null): ?int
    {
        $candidate = is_numeric($requestedId) ? (int) $requestedId : null;

        if ($candidate === null && $actor !== null) {
            $candidate = (int) $actor->id;
        }

        if ($candidate === null || $candidate === (int) $store->user_id) {
            return null;
        }

        $leader = $store->user ?? $store->user()->first();

        if (! $leader instanceof User) {
            return null;
        }

        return $this->belongsToLeader($leader, $candidate) ? $candidate : null;
    }

    public function belongsToLeader(User $leader, int $userId): bool
    {
        if ($userId === (int) $leader->id) {
            return false;
        }

        $onTeam = Referral::query()
            ->where('referrer_id', $leader->id)
            ->where('referred_id', $userId)
            ->whereIn('status', [ReferralStatus::Active, ReferralStatus::Independent])
            ->exists();

        if ($onTeam) {
            return true;
        }

        $sponsored = User::query()
            ->where('id', $userId)
            ->where('sponsor_user_id', $leader->id)
            ->exists();

        if ($sponsored) {
            return true;
        }

        if (! $leader->organization_id) {
            return false;
        }

        $memberIds = $this->slice->memberIdsForLeader($leader);

        if ($memberIds->isEmpty()) {
            return false;
        }

        return OrganizationMember::query()
            ->whereIn('id', $memberIds)
            ->where('user_id', $userId)
            ->exists();
    }
};
