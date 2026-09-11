<?php

declare(strict_types=1);

namespace App\Modules\MLM\Services;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use App\Modules\Organization\Canonical\LeaderCanonicalSlice;
use App\Modules\Organization\Models\OrganizationMember;
use App\Shared\Enums\InvitationStatus;

class TeamRosterService
{
    public function __construct(
        private readonly TeamCrmService $crm,
        private readonly LeaderCanonicalSlice $slice,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, summary: array<string, int|float>}
     */
    public function roster(User $leader): array
    {
        $platform = $this->crm->list($leader);
        $referredEmails = collect($platform)
            ->map(fn (array $row) => mb_strtolower((string) ($row['referred']['email'] ?? '')))
            ->filter()
            ->values();
        $referredUserIds = collect($platform)
            ->map(fn (array $row) => (int) ($row['referred_id'] ?? 0))
            ->filter()
            ->values();

        $pendingEmails = Invitation::query()
            ->where('leader_id', $leader->id)
            ->where('status', InvitationStatus::Pending)
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower((string) $email));

        $platformRows = array_map(function (array $row) {
            $kind = ! empty($row['is_leader']) ? 'leader' : 'partner';

            return [
                ...$row,
                'kind' => $kind,
                'referral_id' => $row['id'],
                'organization_member_id' => null,
                'name' => $row['referred']['name'] ?? '—',
                'email' => $row['referred']['email'] ?? null,
                'phone' => null,
                'company_code' => null,
                'rank_name' => null,
                'invite_pending' => false,
                'can_convert' => false,
            ];
        }, $platform);

        $companyRows = $this->slice->membersForLeader($leader)
            ->filter(function (OrganizationMember $member) use ($referredEmails, $referredUserIds) {
                if ($member->user_id && $referredUserIds->contains((int) $member->user_id)) {
                    return false;
                }
                $email = mb_strtolower((string) $member->email);
                if ($email !== '' && $referredEmails->contains($email)) {
                    return false;
                }

                return true;
            })
            ->map(function (OrganizationMember $member) use ($pendingEmails) {
                $email = mb_strtolower((string) $member->email);
                $invitePending = $email !== '' && $pendingEmails->contains($email);

                return [
                    'kind' => 'company',
                    'id' => $member->id,
                    'referral_id' => null,
                    'organization_member_id' => $member->id,
                    'referrer_id' => null,
                    'referred_id' => $member->user_id,
                    'network_id' => $member->network_id,
                    'level' => null,
                    'status' => $member->status,
                    'crm_stage' => null,
                    'notes' => null,
                    'follow_up_at' => null,
                    'last_contacted_at' => null,
                    'created_at' => $member->created_at,
                    'is_leader' => false,
                    'role' => 'company',
                    'downline_count' => 0,
                    'sales_month' => 0,
                    'sales_total' => 0,
                    'orders_count' => 0,
                    'roles' => [],
                    'name' => $member->name ?: ($member->email ?: 'Sin nombre'),
                    'email' => $member->email,
                    'phone' => $member->phone,
                    'company_code' => $member->external_code,
                    'rank_name' => $member->rank_name,
                    'invite_pending' => $invitePending,
                    'can_convert' => filled($member->email) && ! $member->user_id,
                    'referred' => [
                        'id' => $member->user_id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'status' => $member->status,
                        'created_at' => $member->created_at,
                    ],
                ];
            })
            ->values()
            ->all();

        $data = array_values([...$platformRows, ...$companyRows]);

        return [
            'data' => $data,
            'summary' => [
                ...$this->crm->summary($leader, collect($platform)),
                'company_partners' => count($companyRows),
            ],
        ];
    }
}
