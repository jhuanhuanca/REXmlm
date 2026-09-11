<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Commission\Models\Commission;
use App\Modules\MLM\Models\Referral;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\CommissionStatus;
use App\Services\Catalog\CatalogCompanyNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdminOverviewReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();

        $generated = $this->commissionTotals($from, $to, 'created_at');
        $paid = $this->paidCommissionTotals($from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group' => $group,
            'generated_at' => now()->toIso8601String(),
            'totals' => [
                'users_new' => $this->usersInRange($from, $to)->count(),
                'leaders_new' => $this->usersInRange($from, $to)->whereHas('roles', fn ($q) => $q->where('name', 'leader'))->count(),
                'partners_new' => $this->usersInRange($from, $to)->whereHas('roles', fn ($q) => $q->where('name', 'partner'))->count(),
                'commissions_generated_count' => $generated['count'],
                'commissions_generated_amount' => $generated['amount'],
                'commissions_paid_count' => $paid['count'],
                'commissions_paid_amount' => $paid['amount'],
                'commissions_pending_amount' => $this->pendingAmount($from, $to),
                'plan_sales_count' => $this->planSalesCount($from, $to),
            ],
            'series' => $this->series($from, $to, $group),
            'commissions_by_user' => $this->commissionsByUser($from, $to),
            'paid_commissions' => $this->paidCommissions($from, $to),
            'plan_sales' => $this->planSales($from, $to),
            'top_companies' => $this->topCompanies($from, $to),
            'top_leaders' => $this->topLeaders($from, $to),
        ];
    }

    private function usersInRange(CarbonImmutable $from, CarbonImmutable $to)
    {
        return User::query()->whereBetween('created_at', [$from, $to]);
    }

    /**
     * @return array{count: int, amount: float}
     */
    private function commissionTotals(CarbonImmutable $from, CarbonImmutable $to, string $column): array
    {
        $row = Commission::query()
            ->whereBetween($column, [$from, $to])
            ->whereNotIn('status', [CommissionStatus::Cancelled, CommissionStatus::Reversed])
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        return [
            'count' => (int) ($row?->total_count ?? 0),
            'amount' => round((float) ($row?->total_amount ?? 0), 2),
        ];
    }

    /**
     * @return array{count: int, amount: float}
     */
    private function paidCommissionTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = Commission::query()
            ->where('status', CommissionStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        return [
            'count' => (int) ($row?->total_count ?? 0),
            'amount' => round((float) ($row?->total_amount ?? 0), 2),
        ];
    }

    private function pendingAmount(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) Commission::query()
            ->where('status', CommissionStatus::Pending)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount'), 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function series(CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        $users = $this->groupedCount(User::query()->whereBetween('created_at', [$from, $to]), 'created_at', $group);
        $leaders = $this->groupedCount(
            User::query()->whereHas('roles', fn ($q) => $q->where('name', 'leader'))->whereBetween('created_at', [$from, $to]),
            'created_at',
            $group,
        );
        $partners = $this->groupedCount(
            User::query()->whereHas('roles', fn ($q) => $q->where('name', 'partner'))->whereBetween('created_at', [$from, $to]),
            'created_at',
            $group,
        );
        $generated = $this->groupedSum(
            Commission::query()
                ->whereBetween('created_at', [$from, $to])
                ->whereNotIn('status', [CommissionStatus::Cancelled, CommissionStatus::Reversed]),
            'created_at',
            $group,
        );
        $paid = $this->groupedSum(
            Commission::query()->where('status', CommissionStatus::Paid)->whereBetween('paid_at', [$from, $to]),
            'paid_at',
            $group,
        );
        $stripePlans = $this->groupedCount(
            Subscription::query()->whereBetween('created_at', [$from, $to]),
            'created_at',
            $group,
        );
        $offlinePlans = $this->groupedCount(
            Commission::query()->where('source_type', 'partner_upgrade')->whereBetween('created_at', [$from, $to]),
            'created_at',
            $group,
        );

        $periods = collect([
            ...array_keys($users),
            ...array_keys($leaders),
            ...array_keys($partners),
            ...array_keys($generated),
            ...array_keys($paid),
            ...array_keys($stripePlans),
            ...array_keys($offlinePlans),
        ])->unique()->sort()->values();

        return $periods->map(function (string $period) use ($users, $leaders, $partners, $generated, $paid, $stripePlans, $offlinePlans) {
            return [
                'period' => $period,
                'users_new' => (int) ($users[$period] ?? 0),
                'leaders_new' => (int) ($leaders[$period] ?? 0),
                'partners_new' => (int) ($partners[$period] ?? 0),
                'commissions_generated' => round((float) ($generated[$period] ?? 0), 2),
                'commissions_paid' => round((float) ($paid[$period] ?? 0), 2),
                'plan_sales' => (int) ($stripePlans[$period] ?? 0) + (int) ($offlinePlans[$period] ?? 0),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commissionsByUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Commission::query()
            ->join('users', 'users.id', '=', 'commissions.referrer_id')
            ->whereNull('users.deleted_at')
            ->whereBetween('commissions.created_at', [$from, $to])
            ->whereNotIn('commissions.status', [CommissionStatus::Cancelled, CommissionStatus::Reversed])
            ->select('commissions.referrer_id', 'users.name', 'users.email')
            ->selectRaw('COUNT(*) as generated_count')
            ->selectRaw('COALESCE(SUM(commissions.amount), 0) as generated_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN commissions.status = 'paid' THEN commissions.amount ELSE 0 END), 0) as paid_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN commissions.status = 'pending' THEN commissions.amount ELSE 0 END), 0) as pending_amount")
            ->groupBy('commissions.referrer_id', 'users.name', 'users.email')
            ->orderByDesc('generated_amount')
            ->limit(25)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->referrer_id,
                'name' => $row->name,
                'email' => $row->email,
                'generated_count' => (int) $row->generated_count,
                'generated_amount' => round((float) $row->generated_amount, 2),
                'paid_amount' => round((float) $row->paid_amount, 2),
                'pending_amount' => round((float) $row->pending_amount, 2),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paidCommissions(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Commission::query()
            ->with(['referrer:id,name,email', 'referred:id,name,email'])
            ->where('status', CommissionStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->latest('paid_at')
            ->limit(80)
            ->get()
            ->map(fn (Commission $row) => [
                'id' => $row->id,
                'amount' => round((float) $row->amount, 2),
                'currency' => $row->currency,
                'paid_at' => optional($row->paid_at)?->toIso8601String(),
                'referrer' => $row->referrer ? [
                    'id' => $row->referrer->id,
                    'name' => $row->referrer->name,
                    'email' => $row->referrer->email,
                ] : null,
                'referred' => $row->referred ? [
                    'id' => $row->referred->id,
                    'name' => $row->referred->name,
                    'email' => $row->referred->email,
                ] : null,
            ])
            ->all();
    }

    private function planSalesCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $stripe = Subscription::query()->whereBetween('created_at', [$from, $to])->count();
        $offline = Commission::query()
            ->where('source_type', 'partner_upgrade')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return $stripe + $offline;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planSales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stripe = Subscription::query()
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereBetween('subscriptions.created_at', [$from, $to])
            ->selectRaw("COALESCE(plans.name, 'Sin plan') as plan_name")
            ->selectRaw('COUNT(*) as sales')
            ->selectRaw('COALESCE(SUM(plans.price), 0) as revenue')
            ->groupByRaw("COALESCE(plans.name, 'Sin plan')")
            ->get();

        $planName = $this->jsonText('meta', '$.plan_name');
        $planPrice = $this->jsonText('meta', '$.plan_price');

        $offline = Commission::query()
            ->where('source_type', 'partner_upgrade')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("COALESCE({$planName}, 'Plan') as plan_name")
            ->selectRaw('COUNT(*) as sales')
            ->selectRaw("COALESCE(SUM({$planPrice}), 0) as revenue")
            ->groupByRaw("COALESCE({$planName}, 'Plan')")
            ->get();

        $merged = [];
        foreach ([$stripe, $offline] as $rows) {
            foreach ($rows as $row) {
                $name = (string) ($row->plan_name ?: 'Plan');
                if (! isset($merged[$name])) {
                    $merged[$name] = ['plan_name' => $name, 'sales' => 0, 'revenue' => 0.0];
                }
                $merged[$name]['sales'] += (int) $row->sales;
                $merged[$name]['revenue'] += (float) $row->revenue;
            }
        }

        return collect($merged)
            ->map(fn (array $row) => [
                'plan_name' => $row['plan_name'],
                'sales' => (int) $row['sales'],
                'revenue' => round((float) $row['revenue'], 2),
            ])
            ->sortByDesc('sales')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topCompanies(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromSql = $from->toDateTimeString();
        $toSql = $to->toDateTimeString();

        $names = app(CatalogCompanyNames::class);

        return User::query()
            ->select('catalog_company_id', 'catalog_company_name')
            ->selectRaw('COUNT(*) as users')
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as users_in_period', [$fromSql, $toSql])
            ->whereNotNull('catalog_company_id')
            ->groupBy('catalog_company_id', 'catalog_company_name')
            ->orderByDesc('users')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'company_id' => $row->catalog_company_id ? (int) $row->catalog_company_id : null,
                'name' => $names->name(
                    $row->catalog_company_id ? (int) $row->catalog_company_id : null,
                    $row->catalog_company_name ?: 'Empresa #'.$row->catalog_company_id,
                ),
                'users' => (int) $row->users,
                'users_in_period' => (int) $row->users_in_period,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topLeaders(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromSql = $from->toDateTimeString();
        $toSql = $to->toDateTimeString();

        return Referral::query()
            ->join('users', 'users.id', '=', 'referrals.referrer_id')
            ->whereNull('users.deleted_at')
            ->where('referrals.level', 1)
            ->select('referrals.referrer_id', 'users.name', 'users.email')
            ->selectRaw('COUNT(*) as partners')
            ->selectRaw('SUM(CASE WHEN referrals.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as partners_in_period', [$fromSql, $toSql])
            ->groupBy('referrals.referrer_id', 'users.name', 'users.email')
            ->orderByDesc('partners')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->referrer_id,
                'name' => $row->name,
                'email' => $row->email,
                'partners' => (int) $row->partners,
                'partners_in_period' => (int) $row->partners_in_period,
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function groupedCount($query, string $column, string $group): array
    {
        $expr = $this->periodExpr($column, $group);

        return $query
            ->selectRaw("{$expr} as period, COUNT(*) as total")
            ->groupByRaw($expr)
            ->orderByRaw($expr)
            ->pluck('total', 'period')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, float>
     */
    private function groupedSum($query, string $column, string $group): array
    {
        $expr = $this->periodExpr($column, $group);

        return $query
            ->selectRaw("{$expr} as period, COALESCE(SUM(amount), 0) as total")
            ->groupByRaw($expr)
            ->orderByRaw($expr)
            ->pluck('total', 'period')
            ->map(fn ($value) => round((float) $value, 2))
            ->all();
    }

    private function periodExpr(string $column, string $group): string
    {
        $allowed = ['created_at', 'paid_at'];
        if (! in_array($column, $allowed, true)) {
            $column = 'created_at';
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return match ($group) {
                'day' => "strftime('%Y-%m-%d', {$column})",
                'year' => "strftime('%Y', {$column})",
                default => "strftime('%Y-%m', {$column})",
            };
        }

        return match ($group) {
            'day' => "DATE({$column})",
            'year' => "DATE_FORMAT({$column}, '%Y')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    private function jsonText(string $column, string $path): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "json_extract({$column}, '{$path}')";
        }

        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))";
    }
}
