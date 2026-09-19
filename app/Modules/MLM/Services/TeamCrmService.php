<?php

declare(strict_types=1);

namespace App\Modules\MLM\Services;

use App\Models\User;
use App\Modules\MLM\Models\Referral;
use App\Modules\MLM\Models\TeamActivity;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Services\StoreSellerGrantService;
use App\Shared\Auth\Owned;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ReferralStatus;
use App\Shared\Enums\TeamActivityType;
use App\Shared\Enums\TeamCrmStage;
use Illuminate\Support\Collection;

class TeamCrmService
{
    public function __construct(
        private readonly StoreSellerGrantService $sellers,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $leader): array
    {
        $leader->loadMissing('store');
        $storeId = $leader->store?->id;
        $monthStart = now()->startOfMonth();

        $referrals = Referral::query()
            ->with(['referred:id,name,email,status,created_at,catalog_company_id'])
            ->where('referrer_id', $leader->id)
            ->orderByDesc('created_at')
            ->get();

        $referredIds = $referrals->pluck('referred_id')->filter()->map(fn ($id) => (int) $id)->all();

        $downlines = collect();
        $salesMonth = collect();
        $salesTotal = collect();
        $ordersCount = collect();
        $roles = collect();

        if ($referredIds !== []) {
            $downlines = Referral::query()
                ->selectRaw('referrer_id, COUNT(*) as aggregate')
                ->whereIn('referrer_id', $referredIds)
                ->groupBy('referrer_id')
                ->pluck('aggregate', 'referrer_id');

            $roles = User::query()
                ->with('roles')
                ->whereIn('id', $referredIds)
                ->get()
                ->mapWithKeys(fn (User $user) => [$user->id => $user->getRoleNames()->all()]);
        }

        $companyId = $leader->workingCatalogCompanyId();
        $primary = $leader->isWorkingPrimaryCompany();

        $referrals = $referrals->filter(function (Referral $referral) use ($companyId, $primary) {
            if (! $companyId) {
                return true;
            }

            $referredCompany = $referral->referred?->catalog_company_id
                ? (int) $referral->referred->catalog_company_id
                : null;

            if ($referredCompany === $companyId) {
                return true;
            }

            return $referredCompany === null && $primary;
        })->values();

        $referredIds = $referrals->pluck('referred_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($storeId && $referredIds !== []) {
            $salesMonth = Order::query()
                ->selectRaw('partner_user_id, COALESCE(SUM(total), 0) as aggregate')
                ->where('store_id', $storeId)
                ->whereIn('partner_user_id', $referredIds)
                ->where('status', OrderStatus::Paid)
                ->whereBetween('paid_at', [$monthStart, now()])
                ->groupBy('partner_user_id')
                ->pluck('aggregate', 'partner_user_id');

            $salesTotal = Order::query()
                ->selectRaw('partner_user_id, COALESCE(SUM(total), 0) as aggregate')
                ->where('store_id', $storeId)
                ->whereIn('partner_user_id', $referredIds)
                ->where('status', OrderStatus::Paid)
                ->groupBy('partner_user_id')
                ->pluck('aggregate', 'partner_user_id');

            $ordersCount = Order::query()
                ->selectRaw('partner_user_id, COUNT(*) as aggregate')
                ->where('store_id', $storeId)
                ->whereIn('partner_user_id', $referredIds)
                ->where('status', OrderStatus::Paid)
                ->groupBy('partner_user_id')
                ->pluck('aggregate', 'partner_user_id');
        }

        $sellerIds = ($storeId && $referredIds !== [])
            ? $this->sellers->partnerIdsForStore($leader->store)
            : [];

        return $referrals->map(function (Referral $referral) use ($downlines, $salesMonth, $salesTotal, $ordersCount, $roles, $sellerIds) {
            $id = (int) $referral->referred_id;
            $memberRoles = $roles[$id] ?? [];
            $isLeader = in_array('leader', $memberRoles, true)
                || $referral->status === ReferralStatus::Independent;

            return $this->serializeMember(
                $referral,
                $isLeader,
                (int) ($downlines[$id] ?? 0),
                (float) ($salesMonth[$id] ?? 0),
                (float) ($salesTotal[$id] ?? 0),
                (int) ($ordersCount[$id] ?? 0),
                $memberRoles,
                in_array($id, $sellerIds, true),
            );
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $leader, int $referralId): array
    {
        $referral = $this->ownedReferral($leader, $referralId);
        $members = collect($this->list($leader));
        $member = $members->firstWhere('id', $referral->id) ?? [
            'id' => $referral->id,
            'referred' => $referral->referred,
        ];

        $storeId = $leader->store?->id;
        $orders = [];

        if ($storeId) {
            $orders = Order::query()
                ->with('items')
                ->where('store_id', $storeId)
                ->where('partner_user_id', $referral->referred_id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'customer_name' => $order->customer_name,
                    'customer_email' => $order->customer_email,
                    'total' => $order->total,
                    'currency' => $order->currency,
                    'status' => $order->status,
                    'paid_at' => $order->paid_at,
                    'created_at' => $order->created_at,
                    'items' => $order->items,
                ])
                ->all();
        }

        $activities = TeamActivity::query()
            ->where('referral_id', $referral->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (TeamActivity $activity) => $this->serializeActivity($activity))
            ->all();

        $downline = Referral::query()
            ->with('referred:id,name,email,created_at')
            ->where('referrer_id', $referral->referred_id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Referral $row) => [
                'id' => $row->id,
                'status' => $row->status instanceof ReferralStatus ? $row->status->value : $row->status,
                'created_at' => $row->created_at,
                'referred' => $row->referred,
            ])
            ->all();

        return [
            ...$member,
            'orders' => $orders,
            'activities' => $activities,
            'downline' => $downline,
        ];
    }

    /**
     * @param  array{crm_stage?: string, notes?: string|null, follow_up_at?: string|null, can_sell_inventory?: bool}  $data
     * @return array<string, mixed>
     */
    public function update(User $leader, int $referralId, array $data): array
    {
        $leader->loadMissing('store');
        $referral = $this->ownedReferral($leader, $referralId);
        $stage = isset($data['crm_stage'])
            ? TeamCrmStage::from($data['crm_stage'])
            : $referral->crm_stage;

        $referral->forceFill([
            'crm_stage' => $stage,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $referral->notes,
            'follow_up_at' => array_key_exists('follow_up_at', $data)
                ? ($data['follow_up_at'] ?: null)
                : $referral->follow_up_at,
            'last_contacted_at' => now(),
        ])->save();

        if (array_key_exists('can_sell_inventory', $data) && $referral->referred_id && $leader->store) {
            if ($data['can_sell_inventory']) {
                $this->sellers->grant($leader->store, (int) $referral->referred_id, $leader);
            } else {
                $this->sellers->revoke($leader->store, (int) $referral->referred_id);
            }
        }

        return $this->show($leader, $referral->id);
    }

    /**
     * @param  array{type?: string, body: string, due_at?: string|null}  $data
     */
    public function addActivity(User $leader, int $referralId, array $data): TeamActivity
    {
        $referral = $this->ownedReferral($leader, $referralId);
        $type = TeamActivityType::tryFrom((string) ($data['type'] ?? 'note')) ?? TeamActivityType::Note;

        $activity = TeamActivity::create([
            'leader_id' => $leader->id,
            'referred_id' => $referral->referred_id,
            'referral_id' => $referral->id,
            'type' => $type,
            'body' => $data['body'],
            'due_at' => $data['due_at'] ?? null,
        ]);

        $updates = ['last_contacted_at' => now()];

        if ($type === TeamActivityType::FollowUp && filled($data['due_at'] ?? null)) {
            $updates['follow_up_at'] = $data['due_at'];
            $updates['crm_stage'] = TeamCrmStage::FollowUp;
        }

        if ($type === TeamActivityType::Support) {
            $updates['crm_stage'] = TeamCrmStage::NeedsSupport;
        }

        $referral->forceFill($updates)->save();

        return $activity;
    }

    /**
     * @return array<string, int|float>
     */
    public function summary(User $leader, Collection $members): array
    {
        $now = now();

        return [
            'partners' => $members->where('is_leader', false)->count(),
            'independent_leaders' => $members->where('is_leader', true)->count(),
            'downline_total' => (int) $members->sum('downline_count'),
            'sales_month' => round((float) $members->sum('sales_month'), 2),
            'follow_ups_due' => $members
                ->filter(fn (array $row) => filled($row['follow_up_at']) && $row['follow_up_at'] <= $now)
                ->count(),
        ];
    }

    public function ownedReferral(User $leader, int $referralId): Referral
    {
        return Owned::find(
            'update',
            Referral::query()
                ->with('referred:id,name,email,status,created_at')
                ->find($referralId),
            $leader,
        );
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function serializeMember(
        Referral $referral,
        bool $isLeader,
        int $downlineCount,
        float $salesMonth,
        float $salesTotal,
        int $ordersCount,
        array $roles,
        bool $canSellInventory = false,
    ): array {
        return [
            'id' => $referral->id,
            'referrer_id' => $referral->referrer_id,
            'referred_id' => $referral->referred_id,
            'network_id' => $referral->network_id,
            'level' => $referral->level,
            'status' => $referral->status instanceof ReferralStatus ? $referral->status->value : $referral->status,
            'crm_stage' => $referral->crm_stage?->value ?? TeamCrmStage::New->value,
            'notes' => $referral->notes,
            'follow_up_at' => $referral->follow_up_at,
            'last_contacted_at' => $referral->last_contacted_at,
            'created_at' => $referral->created_at,
            'is_leader' => $isLeader,
            'role' => $isLeader ? 'leader' : 'partner',
            'downline_count' => $downlineCount,
            'sales_month' => round($salesMonth, 2),
            'sales_total' => round($salesTotal, 2),
            'orders_count' => $ordersCount,
            'roles' => $roles,
            'can_sell_inventory' => $canSellInventory,
            'referred' => $referral->referred,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivity(TeamActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'body' => $activity->body,
            'due_at' => $activity->due_at,
            'completed_at' => $activity->completed_at,
            'created_at' => $activity->created_at,
        ];
    }
}
