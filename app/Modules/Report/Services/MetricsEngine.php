<?php

declare(strict_types=1);

namespace App\Modules\Report\Services;

use App\Models\User;
use App\Modules\Organization\Canonical\LeaderCanonicalSlice;
use App\Modules\Organization\Metrics\OrganizationMetricsProfile;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationOrder;
use App\Modules\Organization\Models\OrganizationOrderItem;
use App\Modules\Organization\Models\OrganizationSponsor;
use App\Modules\Organization\Models\OrganizationVolume;
use Illuminate\Support\Collection;

class MetricsEngine
{
    public function __construct(
        private readonly LeaderCanonicalSlice $slice,
    ) {}

    /**
     * Plano B + proxy de tienda. Nunca suma GMV, PV y comisión SaaS.
     *
     * @param  array<string, mixed>  $planeA
     * @return array<string, mixed>
     */
    public function planeB(User $user, string $period, array $planeA): array
    {
        $user->loadMissing(['organization', 'companyMemberships']);
        $profile = OrganizationMetricsProfile::from($user->workingOrganization()?->metrics_profile);
        $volume = $this->slice->for($user, $period);
        $memberIds = $this->slice->memberIdsForLeader($user);
        $leaderMember = $this->leaderMember($user, $memberIds);
        $volumes = $this->bestVolumes($user, $period, $memberIds);

        $volume = $this->applyDerivation($volume, $profile, $user, $period, $leaderMember, $memberIds, $volumes);
        $lines = $this->lines($leaderMember, $volumes, $profile);
        $volume['lines'] = $lines;
        $volume['personal_origin'] ??= $volume['personal'] !== null ? 'imported' : null;
        $volume['group_origin'] ??= $volume['group'] !== null ? 'imported' : null;
        if ($profile->volumeUnit !== '' && ($volume['unit'] === null || $volume['personal_origin'] === 'derived_items')) {
            $volume['unit'] = $volume['unit'] ?: $profile->volumeUnit;
        }

        return [
            'volume' => $volume,
            'store_proxy' => [
                'available' => true,
                'personal' => round((float) ($planeA['sales_direct'] ?? 0), 2),
                'team' => round((float) ($planeA['sales_attributed'] ?? 0), 2),
                'label' => 'tienda REXmlm, no es PV',
            ],
            'qualification' => $this->qualify($profile, $volume, $lines, $leaderMember, $period),
            'rank_progress' => $this->rankProgress($profile, $volume, $lines),
        ];
    }

    /**
     * @param  array<string, mixed>  $volume
     * @param  Collection<int, int>  $memberIds
     * @param  Collection<int, OrganizationVolume>  $volumes
     * @return array<string, mixed>
     */
    private function applyDerivation(
        array $volume,
        OrganizationMetricsProfile $profile,
        User $user,
        string $period,
        ?OrganizationMember $leaderMember,
        Collection $memberIds,
        Collection $volumes,
    ): array {
        if ($volume['personal'] === null && $profile->derivePersonalFromItems && $leaderMember) {
            $fromItems = (float) OrganizationOrderItem::query()
                ->where('organization_id', $user->workingOrganizationId())
                ->whereHas('order', function ($query) use ($leaderMember, $period) {
                    $query->where('member_id', $leaderMember->id)->where('period', $period);
                })
                ->sum('pv');
            if ($fromItems > 0) {
                $volume['personal'] = $fromItems;
                $volume['personal_origin'] = 'derived_items';
                $volume['available'] = true;
                $volume['unit'] = $volume['unit'] ?: $profile->volumeUnit;
            }
        } elseif ($volume['personal'] !== null) {
            $volume['personal_origin'] = 'imported';
        }

        if ($volume['group'] === null && $profile->deriveGroupFromDownline && $leaderMember) {
            $ids = $this->groupMemberIds($leaderMember, $memberIds, $profile);
            $sum = 0.0;
            $known = 0;
            foreach ($ids as $id) {
                if (! $profile->groupIncludesPersonal && $leaderMember->id === (int) $id) {
                    continue;
                }
                $row = $volumes->get($id);
                $personal = $row?->personal_volume;
                if ($id === $leaderMember->id && $personal === null && $volume['personal'] !== null) {
                    $personal = $volume['personal'];
                }
                if ($personal === null) {
                    continue;
                }
                $sum += (float) $personal;
                $known++;
            }
            if ($known > 0) {
                $volume['group'] = $sum;
                $volume['group_origin'] = 'derived_downline';
                $volume['available'] = true;
            }
        } elseif ($volume['group'] !== null) {
            $volume['group_origin'] = 'imported';
        }

        return $volume;
    }

    /**
     * @param  Collection<int, int>  $memberIds
     * @return Collection<int, int>
     */
    private function groupMemberIds(
        OrganizationMember $leaderMember,
        Collection $memberIds,
        OrganizationMetricsProfile $profile,
    ): Collection {
        if ($profile->groupDepth === 1) {
            $children = OrganizationSponsor::query()
                ->where('sponsor_member_id', $leaderMember->id)
                ->pluck('member_id');

            return $profile->groupIncludesPersonal
                ? $children->push($leaderMember->id)->unique()->values()
                : $children->values();
        }

        return $memberIds;
    }

    /**
     * @param  Collection<int, OrganizationVolume>  $volumes
     * @return array{first_level: int, qualified: int, known: int, min_volume: ?float}
     */
    private function lines(
        ?OrganizationMember $leaderMember,
        Collection $volumes,
        OrganizationMetricsProfile $profile,
    ): array {
        $empty = ['first_level' => 0, 'qualified' => 0, 'known' => 0, 'min_volume' => $profile->lineMinVolume];
        if (! $leaderMember) {
            return $empty;
        }

        $childIds = OrganizationSponsor::query()
            ->where('sponsor_member_id', $leaderMember->id)
            ->pluck('member_id');

        $qualified = 0;
        $known = 0;
        foreach ($childIds as $id) {
            $personal = $volumes->get($id)?->personal_volume;
            if ($personal === null) {
                continue;
            }
            $known++;
            if ($profile->lineMinVolume === null || (float) $personal >= $profile->lineMinVolume) {
                $qualified++;
            }
        }

        return [
            'first_level' => $childIds->count(),
            'qualified' => $qualified,
            'known' => $known,
            'min_volume' => $profile->lineMinVolume,
        ];
    }

    /**
     * @param  array<string, mixed>  $volume
     * @param  array{first_level: int, qualified: int, known: int, min_volume: ?float}  $lines
     * @return array<string, mixed>
     */
    private function qualify(
        OrganizationMetricsProfile $profile,
        array $volume,
        array $lines,
        ?OrganizationMember $leaderMember,
        string $period,
    ): array {
        if (! $profile->published) {
            return [
                'status' => 'unpublished',
                'official' => false,
                'checks' => [],
                'message' => 'La empresa no publicó reglas de calificación. REXmlm no afirma si calificaste.',
            ];
        }

        if (! $profile->hasThresholds()) {
            return [
                'status' => 'unpublished',
                'official' => false,
                'checks' => [],
                'message' => 'El perfil está publicado pero no tiene umbrales. No hay calificación.',
            ];
        }

        $checks = [];
        if ($profile->minPersonal !== null) {
            $checks[] = $this->check('personal', $volume['personal'] ?? null, $profile->minPersonal, $volume['unit'] ?? 'PV');
        }
        if ($profile->minGroup !== null) {
            $checks[] = $this->check('group', $volume['group'] ?? null, $profile->minGroup, $volume['unit'] ?? 'PV');
        }
        if ($profile->qualifiedLines !== null) {
            $checks[] = $this->lineCheck($lines, $profile->qualifiedLines);
        }
        if ($profile->maintenance) {
            $orders = 0;
            if ($leaderMember) {
                $orders = OrganizationOrder::query()
                    ->where('member_id', $leaderMember->id)
                    ->where('period', $period)
                    ->where(function ($query) {
                        $query->where('qualifying', true)->orWhereNull('qualifying');
                    })
                    ->count();
            }
            $checks[] = [
                'key' => 'maintenance',
                'label' => 'Pedido de mantenimiento',
                'required' => 1,
                'actual' => $orders > 0 ? $orders : null,
                'met' => $orders > 0,
                'unknown' => $leaderMember === null,
            ];
        }

        $unknown = collect($checks)->contains(fn ($check) => $check['unknown'] === true);
        $failed = collect($checks)->contains(fn ($check) => $check['unknown'] === false && $check['met'] === false);

        if ($unknown) {
            $status = 'insufficient_data';
            $message = 'Falta volumen o líneas para calificar. No se muestra un no-calificado con ceros fingidos.';
        } elseif ($failed) {
            $status = 'not_qualified';
            $message = 'Con las reglas publicadas de la empresa, este mes no califica.';
        } else {
            $status = 'qualified';
            $message = 'Cumple las reglas publicadas de la empresa para este mes.';
        }

        return [
            'status' => $status,
            'official' => true,
            'checks' => $checks,
            'message' => $message,
        ];
    }

    /**
     * @return array{key: string, label: string, required: float, actual: ?float, met: bool, unknown: bool}
     */
    private function check(string $key, mixed $actual, float $required, string $unit): array
    {
        $value = $actual === null ? null : (float) $actual;

        return [
            'key' => $key,
            'label' => $key === 'personal' ? 'Volumen personal ('.$unit.')' : 'Volumen de grupo ('.$unit.')',
            'required' => $required,
            'actual' => $value,
            'met' => $value !== null && $value >= $required,
            'unknown' => $value === null,
        ];
    }

    /**
     * @param  array{first_level: int, qualified: int, known: int, min_volume: ?float}  $lines
     * @return array{key: string, label: string, required: int, actual: ?int, met: bool, unknown: bool}
     */
    private function lineCheck(array $lines, int $required): array
    {
        $unknown = $lines['known'] < $required && $lines['qualified'] < $required;

        return [
            'key' => 'lines',
            'label' => 'Líneas calificadas',
            'required' => $required,
            'actual' => $unknown ? null : $lines['qualified'],
            'met' => $lines['qualified'] >= $required,
            'unknown' => $unknown,
        ];
    }

    /**
     * @param  array<string, mixed>  $volume
     * @param  array{first_level: int, qualified: int, known: int, min_volume: ?float}  $lines
     * @return array<string, mixed>
     */
    private function rankProgress(
        OrganizationMetricsProfile $profile,
        array $volume,
        array $lines,
    ): array {
        $empty = [
            'official' => false,
            'current' => null,
            'next' => null,
            'progress' => null,
            'message' => 'Sin umbrales de rango publicados. La barra de avance queda fuera.',
        ];

        if (! $profile->published || $profile->ranks === []) {
            return $empty;
        }

        if (! ($volume['available'] ?? false)) {
            return [
                'official' => true,
                'current' => null,
                'next' => $profile->ranks[0] ?? null,
                'progress' => null,
                'message' => 'Hay rangos publicados, pero no hay volumen del mes para comparar.',
            ];
        }

        $current = null;
        $next = null;
        foreach ($profile->ranks as $rank) {
            $state = $this->rankState($rank, $volume, $lines);
            if ($state === 'met') {
                $current = $rank;
                continue;
            }
            $next = $rank;
            if ($state === 'unknown') {
                return [
                    'official' => true,
                    'current' => $current,
                    'next' => $next,
                    'progress' => null,
                    'message' => 'Faltan datos para el siguiente rango. No se afirma un avance de 0 %.',
                ];
            }
            break;
        }

        $progress = $next ? $this->progressToward($next, $volume, $lines) : 100.0;

        return [
            'official' => true,
            'current' => $current,
            'next' => $next,
            'progress' => $progress,
            'message' => $next
                ? 'Avance hacia '.$next['name'].' según umbrales publicados.'
                : 'Alcanza el rango más alto publicado.',
        ];
    }

    /**
     * @param  array{code: ?string, name: string, min_personal: ?float, min_group: ?float, min_lines: ?int, sort_order: int}  $rank
     * @param  array<string, mixed>  $volume
     * @param  array{first_level: int, qualified: int, known: int, min_volume: ?float}  $lines
     */
    private function rankState(array $rank, array $volume, array $lines): string
    {
        $parts = [];
        if ($rank['min_personal'] !== null) {
            if ($volume['personal'] === null) {
                return 'unknown';
            }
            $parts[] = (float) $volume['personal'] >= $rank['min_personal'];
        }
        if ($rank['min_group'] !== null) {
            if ($volume['group'] === null) {
                return 'unknown';
            }
            $parts[] = (float) $volume['group'] >= $rank['min_group'];
        }
        if ($rank['min_lines'] !== null) {
            if ($lines['known'] < $rank['min_lines'] && $lines['qualified'] < $rank['min_lines']) {
                return 'unknown';
            }
            $parts[] = $lines['qualified'] >= $rank['min_lines'];
        }

        if ($parts === []) {
            return 'met';
        }

        return in_array(false, $parts, true) ? 'miss' : 'met';
    }

    /**
     * @param  array{code: ?string, name: string, min_personal: ?float, min_group: ?float, min_lines: ?int, sort_order: int}  $rank
     * @param  array<string, mixed>  $volume
     * @param  array{first_level: int, qualified: int, known: int, min_volume: ?float}  $lines
     */
    private function progressToward(array $rank, array $volume, array $lines): float
    {
        $ratios = [];
        if ($rank['min_personal'] && $volume['personal'] !== null) {
            $ratios[] = min(1, (float) $volume['personal'] / $rank['min_personal']);
        }
        if ($rank['min_group'] && $volume['group'] !== null) {
            $ratios[] = min(1, (float) $volume['group'] / $rank['min_group']);
        }
        if ($rank['min_lines'] && $rank['min_lines'] > 0) {
            $ratios[] = min(1, $lines['qualified'] / $rank['min_lines']);
        }

        if ($ratios === []) {
            return 0.0;
        }

        return round((array_sum($ratios) / count($ratios)) * 100, 1);
    }

    /**
     * @param  Collection<int, int>  $memberIds
     * @return Collection<int, OrganizationVolume>
     */
    private function bestVolumes(User $user, string $period, Collection $memberIds): Collection
    {
        if ($memberIds->isEmpty() || ! $user->workingOrganizationId()) {
            return collect();
        }

        return OrganizationVolume::query()
            ->where('organization_id', $user->workingOrganizationId())
            ->where('period', $period)
            ->whereIn('member_id', $memberIds)
            ->orderByDesc('priority')
            ->get()
            ->groupBy('member_id')
            ->map(fn (Collection $rows) => $rows->first());
    }

    /**
     * @param  Collection<int, int>  $memberIds
     */
    private function leaderMember(User $user, Collection $memberIds): ?OrganizationMember
    {
        if ($memberIds->isEmpty()) {
            return null;
        }

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
