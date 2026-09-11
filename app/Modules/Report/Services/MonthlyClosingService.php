<?php

declare(strict_types=1);

namespace App\Modules\Report\Services;

use App\Models\User;
use App\Modules\Commission\Models\Commission;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Services\ClosingTimezone;
use App\Modules\Store\Models\Order;
use App\Shared\Enums\InvitationStatus;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ReferralStatus;
use App\Shared\Enums\TeamCrmStage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class MonthlyClosingService
{
    public function __construct(
        private readonly ClosingTimezone $closingTimezone,
        private readonly MetricsEngine $metricsEngine,
        private readonly PeriodGoalsService $periodGoals,
    ) {}

    /**
     * @return array{period: Carbon, start: CarbonInterface, end: CarbonInterface, timezone: string, now: Carbon}
     */
    public function resolvePeriod(User $user, ?string $period): array
    {
        $timezone = $this->closingTimezone->forUser($user);
        $now = Carbon::now($timezone);

        if (filled($period)) {
            if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
                throw new InvalidArgumentException('El periodo debe tener formato YYYY-MM.');
            }

            $start = Carbon::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', $timezone)->startOfMonth();
        } else {
            $start = $now->copy()->startOfMonth();
        }

        $end = $start->copy()->endOfMonth();

        return [
            'period' => $start->copy(),
            'start' => $start->copy()->utc(),
            'end' => $end->copy()->utc(),
            'timezone' => $timezone,
            'now' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?string $period = null): array
    {
        $user->loadMissing(['store:id,user_id', 'organization']);

        ['period' => $month, 'start' => $start, 'end' => $end, 'now' => $now] = $this->resolvePeriod($user, $period);

        $current = $this->snapshot($user, $start, $end);
        $previousStartLocal = $month->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEndLocal = $previousStartLocal->copy()->endOfMonth();
        $previous = $this->snapshot($user, $previousStartLocal->copy()->utc(), $previousEndLocal->copy()->utc());
        $team = $this->teamSnapshot($user, $start, $end);

        $isCurrent = $month->isSameMonth($now);
        $clock = $this->closingTimezone->snapshot($user);
        $metrics = $this->metricsEngine->planeB($user, $month->format('Y-m'), $current);
        $goals = $this->periodGoals->forClosing($user, $month->format('Y-m'), [
            'sales' => $current['sales'],
            'commissions' => $current['commissions'],
            'new_team_members' => $current['new_team_members'],
            'invitations' => $current['invitations'],
            'company_volume' => $metrics['volume'],
        ]);

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->locale('es')->translatedFormat('F Y'),
            'is_current_month' => $isCurrent,
            'days_in_month' => $month->daysInMonth,
            'day_of_month' => $isCurrent ? $now->day : $month->daysInMonth,
            'timezone' => $clock['timezone'],
            'timezone_label' => $clock['timezone_label'],
            'country' => $clock['country'],
            'scope' => $clock['scope'],
            'organization' => $clock['organization'],
            'sales' => $current['sales'],
            'sales_attributed' => $current['sales_attributed'],
            'sales_direct' => $current['sales_direct'],
            'orders_count' => $current['orders_count'],
            'commissions' => $current['commissions'],
            'commissions_breakdown' => $current['commissions_breakdown'],
            'new_team_members' => $current['new_team_members'],
            'new_leaders' => $current['new_leaders'],
            'follow_ups_due' => $current['follow_ups_due'],
            'invitations' => $current['invitations'],
            'top_partners' => $current['top_partners'],
            'team' => $team,
            'previous_month' => $previousStartLocal->format('Y-m'),
            'previous' => [
                'month' => $previousStartLocal->format('Y-m'),
                'sales' => $previous['sales'],
                'commissions' => $previous['commissions'],
                'new_team_members' => $previous['new_team_members'],
                'new_leaders' => $previous['new_leaders'],
                'orders_count' => $previous['orders_count'],
            ],
            'change' => [
                'sales' => $this->changePercent($current['sales'], $previous['sales']),
                'commissions' => $this->changePercent($current['commissions'], $previous['commissions']),
                'new_team_members' => $this->changePercent(
                    (float) $current['new_team_members'],
                    (float) $previous['new_team_members'],
                ),
                'new_leaders' => $this->changePercent(
                    (float) $current['new_leaders'],
                    (float) $previous['new_leaders'],
                ),
            ],
            'company_volume' => $metrics['volume'],
            'store_proxy' => $metrics['store_proxy'],
            'qualification' => $metrics['qualification'],
            'rank_progress' => $metrics['rank_progress'],
            'series' => $this->series($user, 6, $month),
            'goals' => $goals,
        ];
    }

    /**
     * Serie corta para gráficos. Plano A y B van separados; PV ausente es null, no 0.
     *
     * @return list<array<string, mixed>>
     */
    public function series(User $user, int $months = 6, ?Carbon $currentMonth = null): array
    {
        $user->loadMissing(['store:id,user_id', 'organization']);
        $currentMonth ??= $this->resolvePeriod($user, null)['period'];
        $points = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $currentMonth->copy()->subMonthsNoOverflow($offset)->startOfMonth();
            $start = $month->copy()->startOfMonth()->utc();
            $end = $month->copy()->endOfMonth()->utc();
            $snap = $this->snapshot($user, $start, $end);
            $planeB = $this->metricsEngine->planeB($user, $month->format('Y-m'), $snap);
            $volume = $planeB['volume'];

            $points[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->locale('es')->translatedFormat('M'),
                'sales' => $snap['sales'],
                'commissions' => $snap['commissions'],
                'personal' => ($volume['available'] ?? false) ? $volume['personal'] : null,
                'group' => ($volume['available'] ?? false) ? $volume['group'] : null,
                'unit' => $volume['unit'] ?? null,
            ];
        }

        return $points;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $storeId = $user->store?->id;
        $sales = 0.0;
        $salesAttributed = 0.0;
        $ordersCount = 0;
        $topPartners = [];

        if ($storeId) {
            $paidQuery = Order::query()
                ->where('store_id', $storeId)
                ->where('status', OrderStatus::Paid)
                ->whereBetween('paid_at', [$start, $end]);

            $sales = (float) (clone $paidQuery)->sum('total');
            $salesAttributed = (float) (clone $paidQuery)->whereNotNull('partner_user_id')->sum('total');
            $ordersCount = (int) (clone $paidQuery)->count();

            $topPartners = Order::query()
                ->selectRaw('partner_user_id, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as sales')
                ->with('partner:id,name,email')
                ->where('store_id', $storeId)
                ->where('status', OrderStatus::Paid)
                ->whereNotNull('partner_user_id')
                ->whereBetween('paid_at', [$start, $end])
                ->groupBy('partner_user_id')
                ->orderByDesc('sales')
                ->limit(5)
                ->get()
                ->map(fn (Order $row) => [
                    'id' => $row->partner_user_id,
                    'name' => $row->partner?->name,
                    'email' => $row->partner?->email,
                    'orders_count' => (int) $row->orders_count,
                    'sales' => round((float) $row->sales, 2),
                ])
                ->all();
        }

        $commissionRows = Commission::query()
            ->where('referrer_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $breakdown = [
            'pending' => round((float) ($commissionRows['pending'] ?? 0), 2),
            'approved' => round((float) ($commissionRows['approved'] ?? 0), 2),
            'paid' => round((float) ($commissionRows['paid'] ?? 0), 2),
            'reversed' => round((float) ($commissionRows['reversed'] ?? 0), 2),
        ];
        $commissions = round(array_sum($breakdown), 2);

        $invitationsSent = Invitation::query()
            ->where('leader_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $invitationsAccepted = Invitation::query()
            ->where('leader_id', $user->id)
            ->where('status', InvitationStatus::Accepted)
            ->whereBetween('accepted_at', [$start, $end])
            ->count();

        $invitationsPending = Invitation::query()
            ->where('leader_id', $user->id)
            ->where('status', InvitationStatus::Pending)
            ->count();

        return [
            'sales' => round($sales, 2),
            'sales_attributed' => round($salesAttributed, 2),
            'sales_direct' => round($sales - $salesAttributed, 2),
            'orders_count' => $ordersCount,
            'commissions' => $commissions,
            'commissions_breakdown' => $breakdown,
            'new_team_members' => Referral::query()
                ->where('referrer_id', $user->id)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'new_leaders' => Referral::query()
                ->where('referrer_id', $user->id)
                ->where('status', ReferralStatus::Independent)
                ->whereBetween('updated_at', [$start, $end])
                ->count(),
            'follow_ups_due' => Referral::query()
                ->where('referrer_id', $user->id)
                ->whereNotNull('follow_up_at')
                ->whereBetween('follow_up_at', [$start, $end])
                ->count(),
            'invitations' => [
                'sent' => $invitationsSent,
                'accepted' => $invitationsAccepted,
                'pending' => $invitationsPending,
            ],
            'top_partners' => $topPartners,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamSnapshot(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        $stages = [
            TeamCrmStage::New->value => 0,
            TeamCrmStage::Contacted->value => 0,
            TeamCrmStage::Active->value => 0,
            TeamCrmStage::FollowUp->value => 0,
            TeamCrmStage::NeedsSupport->value => 0,
            TeamCrmStage::Independent->value => 0,
        ];

        $stageRows = Referral::query()
            ->where('referrer_id', $user->id)
            ->selectRaw('crm_stage, COUNT(*) as total')
            ->groupBy('crm_stage')
            ->pluck('total', 'crm_stage');

        foreach ($stageRows as $stage => $total) {
            $key = $stage instanceof TeamCrmStage ? $stage->value : (string) $stage;
            $stages[$key] = (int) $total;
        }

        $activePartnerIds = Referral::query()
            ->where('referrer_id', $user->id)
            ->where('status', ReferralStatus::Active)
            ->pluck('referred_id');

        $partnersWithSales = collect();
        $storeId = $user->store?->id;

        if ($storeId && $activePartnerIds->isNotEmpty()) {
            $partnersWithSales = Order::query()
                ->where('store_id', $storeId)
                ->where('status', OrderStatus::Paid)
                ->whereBetween('paid_at', [$start, $end])
                ->whereIn('partner_user_id', $activePartnerIds)
                ->distinct()
                ->pluck('partner_user_id');
        }

        $attention = Referral::query()
            ->with('referred:id,name,email')
            ->where('referrer_id', $user->id)
            ->whereIn('crm_stage', [TeamCrmStage::NeedsSupport, TeamCrmStage::FollowUp, TeamCrmStage::New])
            ->orderByRaw("CASE crm_stage WHEN 'needs_support' THEN 1 WHEN 'follow_up' THEN 2 WHEN 'new' THEN 3 ELSE 4 END")
            ->limit(8)
            ->get()
            ->map(fn (Referral $row) => [
                'id' => $row->id,
                'name' => $row->referred?->name,
                'email' => $row->referred?->email,
                'crm_stage' => $row->crm_stage?->value ?? TeamCrmStage::New->value,
                'follow_up_at' => $row->follow_up_at?->toIso8601String(),
            ])
            ->all();

        return [
            'total' => array_sum($stages),
            'by_stage' => $stages,
            'needs_support' => $stages[TeamCrmStage::NeedsSupport->value],
            'follow_ups_overdue' => Referral::query()
                ->where('referrer_id', $user->id)
                ->whereNotNull('follow_up_at')
                ->where('follow_up_at', '<=', now())
                ->where('status', '!=', ReferralStatus::Independent)
                ->count(),
            'partners_without_sales' => $activePartnerIds->diff($partnersWithSales)->count(),
            'attention' => $attention,
        ];
    }

    private function changePercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
