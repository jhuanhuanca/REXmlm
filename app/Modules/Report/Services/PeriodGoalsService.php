<?php

declare(strict_types=1);

namespace App\Modules\Report\Services;

use App\Models\User;
use App\Modules\Report\Models\PeriodGoal;
use App\Shared\Enums\PeriodGoalMetric;
use Carbon\Carbon;
use InvalidArgumentException;

class PeriodGoalsService
{
    /**
     * @param  list<array{metric: string, target: mixed}>  $rows
     * @return list<array<string, mixed>>
     */
    public function upsert(User $user, string $period, array $rows): array
    {
        $this->assertPeriod($period);
        $user->loadMissing(['organization']);

        foreach ($rows as $row) {
            $metric = PeriodGoalMetric::from((string) $row['metric']);
            $raw = $row['target'] ?? null;

            if ($raw === null || $raw === '') {
                PeriodGoal::query()
                    ->where('user_id', $user->id)
                    ->where('period', $period)
                    ->where('metric', $metric->value)
                    ->delete();

                continue;
            }

            PeriodGoal::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'period' => $period,
                    'metric' => $metric->value,
                ],
                [
                    'network_id' => $user->current_network_id,
                    'organization_id' => $user->workingOrganizationId(),
                    'plane' => $metric->plane(),
                    'target' => round((float) $raw, 4),
                ],
            );
        }

        return $this->catalog($user, $period);
    }

    /**
     * @param  array<string, mixed>  $closing
     * @return array{period: string, next_period: string, items: list<array<string, mixed>>, next_items: list<array<string, mixed>>}
     */
    public function forClosing(User $user, string $period, array $closing): array
    {
        $this->assertPeriod($period);
        $next = Carbon::createFromFormat('Y-m-d', $period.'-01')?->addMonthNoOverflow()->format('Y-m') ?? $period;

        return [
            'period' => $period,
            'next_period' => $next,
            'items' => $this->evaluate($user, $period, $closing),
            'next_items' => $this->catalog($user, $next),
        ];
    }

    /**
     * @param  array<string, mixed>  $closing
     * @return list<array<string, mixed>>
     */
    public function evaluate(User $user, string $period, array $closing): array
    {
        $saved = $this->targetsByMetric($user, $period);
        $items = [];

        foreach (PeriodGoalMetric::cases() as $metric) {
            $target = $saved[$metric->value] ?? null;
            $actual = $this->actual($metric, $closing);
            $unit = $this->displayUnit($metric, $closing);

            $progress = null;
            $status = 'empty';

            if ($target === null) {
                $items[] = $this->item($metric, $unit, null, $actual, null, $status);
                continue;
            }

            if ($actual === null) {
                $status = 'unavailable';
            } elseif ($actual + 0.0001 >= $target) {
                $status = 'met';
                $progress = 100.0;
            } else {
                $status = 'open';
                $progress = $target > 0 ? round(($actual / $target) * 100, 1) : 0.0;
            }

            $items[] = $this->item($metric, $unit, $target, $actual, $progress, $status);
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(User $user, string $period): array
    {
        $saved = $this->targetsByMetric($user, $period);
        $items = [];

        foreach (PeriodGoalMetric::cases() as $metric) {
            $target = $saved[$metric->value] ?? null;
            $items[] = $this->item(
                $metric,
                $metric->unit(),
                $target,
                null,
                null,
                $target === null ? 'empty' : 'planned',
            );
        }

        return $items;
    }

    /**
     * @return array<string, float>
     */
    private function targetsByMetric(User $user, string $period): array
    {
        return PeriodGoal::query()
            ->where('user_id', $user->id)
            ->where('period', $period)
            ->get()
            ->mapWithKeys(fn (PeriodGoal $row) => [
                ($row->metric instanceof PeriodGoalMetric ? $row->metric->value : (string) $row->metric) => (float) $row->target,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $closing
     */
    private function actual(PeriodGoalMetric $metric, array $closing): ?float
    {
        $volume = $closing['company_volume'] ?? [];
        $available = (bool) ($volume['available'] ?? false);

        return match ($metric) {
            PeriodGoalMetric::StoreSales => round((float) ($closing['sales'] ?? 0), 2),
            PeriodGoalMetric::Commissions => round((float) ($closing['commissions'] ?? 0), 2),
            PeriodGoalMetric::NewMembers => (float) ($closing['new_team_members'] ?? 0),
            PeriodGoalMetric::Invitations => (float) ($closing['invitations']['sent'] ?? 0),
            PeriodGoalMetric::PersonalVolume => $available ? $this->nullableNumber($volume['personal'] ?? null) : null,
            PeriodGoalMetric::GroupVolume => $available ? $this->nullableNumber($volume['group'] ?? null) : null,
        };
    }

    /**
     * @param  array<string, mixed>  $closing
     */
    private function displayUnit(PeriodGoalMetric $metric, array $closing): string
    {
        if ($metric->plane() === 'b') {
            $unit = $closing['company_volume']['unit'] ?? null;

            return is_string($unit) && $unit !== '' ? $unit : 'PV';
        }

        return $metric->unit();
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        PeriodGoalMetric $metric,
        string $unit,
        ?float $target,
        ?float $actual,
        ?float $progress,
        string $status,
    ): array {
        return [
            'metric' => $metric->value,
            'label' => $metric->label(),
            'hint' => $metric->hint(),
            'plane' => $metric->plane(),
            'unit' => $unit,
            'target' => $target,
            'actual' => $actual,
            'progress' => $progress,
            'status' => $status,
        ];
    }

    private function assertPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException('El periodo debe tener formato YYYY-MM.');
        }
    }
}
