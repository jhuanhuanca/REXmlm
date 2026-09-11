<?php

declare(strict_types=1);

namespace App\Modules\MLM\Services;

use App\Models\User;
use App\Modules\Commission\Models\Commission;
use App\Modules\MLM\Models\Referral;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Shared\Enums\CommissionStatus;
use App\Shared\Enums\ReferralStatus;

class DashboardMetricsService
{
    public function __construct(
        private readonly MonthlyClosingService $monthlyClosing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $user->loadMissing(['store:id,user_id', 'organization']);

        $directReferrals = Referral::query()
            ->where('referrer_id', $user->id)
            ->where('level', 1)
            ->count();

        $totalTeam = Referral::query()
            ->where('referrer_id', $user->id)
            ->count();

        $independentLeaders = Referral::query()
            ->where('referrer_id', $user->id)
            ->where('status', ReferralStatus::Independent)
            ->count();

        $followUpsDue = Referral::query()
            ->where('referrer_id', $user->id)
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            ->count();

        $pendingCommissions = (float) Commission::query()
            ->where('referrer_id', $user->id)
            ->where('status', CommissionStatus::Pending)
            ->sum('amount');

        $paidCommissions = (float) Commission::query()
            ->where('referrer_id', $user->id)
            ->where('status', CommissionStatus::Paid)
            ->sum('amount');

        $closing = $this->monthlyClosing->build($user);

        return [
            'direct_referrals' => $directReferrals,
            'total_team' => $totalTeam,
            'independent_leaders' => $independentLeaders,
            'follow_ups_due' => $followUpsDue,
            'pending_commissions' => round($pendingCommissions, 2),
            'total_commissions_earned' => round($paidCommissions, 2),
            'paid_store_sales' => $closing['sales'],
            'team_sales_month' => $closing['sales_attributed'],
            'closing' => [
                'month' => $closing['month'],
                'label' => $closing['label'],
                'is_current_month' => $closing['is_current_month'],
                'days_in_month' => $closing['days_in_month'],
                'day_of_month' => $closing['day_of_month'],
                'timezone' => $closing['timezone'],
                'timezone_label' => $closing['timezone_label'],
                'organization' => $closing['organization'],
                'sales' => $closing['sales'],
                'commissions' => $closing['commissions'],
                'orders_count' => $closing['orders_count'],
                'company_volume' => $closing['company_volume'],
                'store_proxy' => $closing['store_proxy'],
                'qualification' => $closing['qualification'],
                'rank_progress' => $closing['rank_progress'],
                'goals' => $closing['goals'],
            ],
            'series' => $closing['series'],
        ];
    }
}
