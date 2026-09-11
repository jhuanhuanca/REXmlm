<?php

declare(strict_types=1);

namespace App\Modules\Organization\Metrics;

final class OrganizationMetricsProfile
{
    /**
     * @param  list<array{code: ?string, name: string, min_personal: ?float, min_group: ?float, min_lines: ?int, sort_order: int}>  $ranks
     */
    public function __construct(
        public readonly bool $published,
        public readonly string $volumeUnit,
        public readonly bool $derivePersonalFromItems,
        public readonly bool $deriveGroupFromDownline,
        public readonly bool $groupIncludesPersonal,
        public readonly ?int $groupDepth,
        public readonly ?float $minPersonal,
        public readonly ?float $minGroup,
        public readonly ?int $qualifiedLines,
        public readonly ?float $lineMinVolume,
        public readonly bool $maintenance,
        public readonly array $ranks,
    ) {}

    public static function from(?array $raw): self
    {
        $raw = is_array($raw) ? $raw : [];
        $ranks = [];
        foreach ($raw['ranks'] ?? [] as $index => $rank) {
            if (! is_array($rank)) {
                continue;
            }
            $name = trim((string) ($rank['name'] ?? $rank['code'] ?? ''));
            if ($name === '') {
                continue;
            }
            $ranks[] = [
                'code' => filled($rank['code'] ?? null) ? (string) $rank['code'] : null,
                'name' => $name,
                'min_personal' => self::nullableFloat($rank['min_personal'] ?? null),
                'min_group' => self::nullableFloat($rank['min_group'] ?? null),
                'min_lines' => isset($rank['min_lines']) && $rank['min_lines'] !== '' && $rank['min_lines'] !== null
                    ? (int) $rank['min_lines']
                    : null,
                'sort_order' => (int) ($rank['sort_order'] ?? $index),
            ];
        }
        usort($ranks, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return new self(
            published: (bool) ($raw['published'] ?? false),
            volumeUnit: (string) ($raw['volume_unit'] ?? 'PV') ?: 'PV',
            derivePersonalFromItems: (bool) ($raw['derive_personal_from_items'] ?? false),
            deriveGroupFromDownline: (bool) ($raw['derive_group_from_downline'] ?? false),
            groupIncludesPersonal: array_key_exists('group_includes_personal', $raw)
                ? (bool) $raw['group_includes_personal']
                : true,
            groupDepth: isset($raw['group_depth']) && $raw['group_depth'] !== '' && $raw['group_depth'] !== null
                ? max(1, (int) $raw['group_depth'])
                : null,
            minPersonal: self::nullableFloat($raw['min_personal'] ?? null),
            minGroup: self::nullableFloat($raw['min_group'] ?? null),
            qualifiedLines: isset($raw['qualified_lines']) && $raw['qualified_lines'] !== '' && $raw['qualified_lines'] !== null
                ? max(0, (int) $raw['qualified_lines'])
                : null,
            lineMinVolume: self::nullableFloat($raw['line_min_volume'] ?? null),
            maintenance: (bool) ($raw['maintenance'] ?? false),
            ranks: $ranks,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'published' => $this->published,
            'volume_unit' => $this->volumeUnit,
            'derive_personal_from_items' => $this->derivePersonalFromItems,
            'derive_group_from_downline' => $this->deriveGroupFromDownline,
            'group_includes_personal' => $this->groupIncludesPersonal,
            'group_depth' => $this->groupDepth,
            'min_personal' => $this->minPersonal,
            'min_group' => $this->minGroup,
            'qualified_lines' => $this->qualifiedLines,
            'line_min_volume' => $this->lineMinVolume,
            'maintenance' => $this->maintenance,
            'ranks' => $this->ranks,
        ];
    }

    public function hasThresholds(): bool
    {
        return $this->minPersonal !== null
            || $this->minGroup !== null
            || $this->qualifiedLines !== null
            || $this->maintenance
            || $this->ranks !== [];
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
