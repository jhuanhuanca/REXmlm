<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\Store;

class ShippingQuoteService
{
    /**
     * @param  array{country?: string|null, department?: string|null, area?: string|null}  $destination
     * @return array{
     *     applies: bool,
     *     fee: float,
     *     handling_fee: float,
     *     shipping_fee: float,
     *     eta_days: int|null,
     *     zone: string|null,
     *     free: bool,
     *     currency: string
     * }
     */
    public function quote(Store $store, array $destination, float $subtotal): array
    {
        $config = $this->config($store);

        $empty = [
            'applies' => false,
            'fee' => 0.0,
            'handling_fee' => 0.0,
            'shipping_fee' => 0.0,
            'eta_days' => null,
            'zone' => null,
            'free' => false,
            'currency' => $store->currency(),
        ];

        if (! $config['enabled']) {
            return $empty;
        }

        $matched = $this->match($config, $destination);
        $handling = round($config['handling_fee'], 2);
        $shipping = round($matched['fee'], 2);
        $freeFrom = $config['free_shipping_from'];
        $free = $freeFrom !== null && $subtotal >= $freeFrom;

        if ($free) {
            $shipping = 0.0;
            $handling = 0.0;
        }

        return [
            'applies' => true,
            'fee' => round($shipping + $handling, 2),
            'handling_fee' => $handling,
            'shipping_fee' => $shipping,
            'eta_days' => $matched['eta_days'],
            'zone' => $matched['zone'],
            'free' => $free,
            'currency' => $store->currency(),
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     origin_country: string,
     *     origin_department: string,
     *     origin_area: string,
     *     handling_fee: float,
     *     free_shipping_from: float|null,
     *     local_fee: float,
     *     department_fee: float,
     *     national_fee: float,
     *     international_fee: float,
     *     local_days: int,
     *     department_days: int,
     *     national_days: int,
     *     international_days: int,
     *     notes: string,
     *     zones: list<array{country: string, department: string, area: string, fee: float, eta_days: int|null, label: string}>
     * }
     */
    public function config(Store $store): array
    {
        $raw = is_array($store->settings) ? ($store->settings['dropshipping'] ?? []) : [];
        $raw = is_array($raw) ? $raw : [];

        $zones = [];
        foreach ($raw['zones'] ?? [] as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $country = strtoupper(trim((string) ($zone['country'] ?? '')));
            if ($country === '') {
                continue;
            }
            $zones[] = [
                'country' => $country,
                'department' => trim((string) ($zone['department'] ?? '')),
                'area' => trim((string) ($zone['area'] ?? '')),
                'fee' => max(0, (float) ($zone['fee'] ?? 0)),
                'eta_days' => isset($zone['eta_days']) && $zone['eta_days'] !== '' && $zone['eta_days'] !== null
                    ? max(0, (int) $zone['eta_days'])
                    : null,
                'label' => trim((string) ($zone['label'] ?? '')),
            ];
        }

        $free = $raw['free_shipping_from'] ?? null;

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'origin_country' => strtoupper(trim((string) ($raw['origin_country'] ?? ''))),
            'origin_department' => trim((string) ($raw['origin_department'] ?? '')),
            'origin_area' => trim((string) ($raw['origin_area'] ?? '')),
            'handling_fee' => max(0, (float) ($raw['handling_fee'] ?? 0)),
            'free_shipping_from' => $free === null || $free === '' ? null : max(0, (float) $free),
            'local_fee' => max(0, (float) ($raw['local_fee'] ?? 0)),
            'department_fee' => max(0, (float) ($raw['department_fee'] ?? 0)),
            'national_fee' => max(0, (float) ($raw['national_fee'] ?? 0)),
            'international_fee' => max(0, (float) ($raw['international_fee'] ?? 0)),
            'local_days' => max(0, (int) ($raw['local_days'] ?? 1)),
            'department_days' => max(0, (int) ($raw['department_days'] ?? 2)),
            'national_days' => max(0, (int) ($raw['national_days'] ?? 4)),
            'international_days' => max(0, (int) ($raw['international_days'] ?? 10)),
            'notes' => trim((string) ($raw['notes'] ?? '')),
            'zones' => $zones,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{country?: string|null, department?: string|null, area?: string|null}  $destination
     * @return array{fee: float, eta_days: int|null, zone: string}
     */
    private function match(array $config, array $destination): array
    {
        $country = strtoupper(trim((string) ($destination['country'] ?? '')));
        $department = $this->fold((string) ($destination['department'] ?? ''));
        $area = $this->fold((string) ($destination['area'] ?? ''));

        $best = null;
        $bestScore = -1;

        foreach ($config['zones'] as $zone) {
            if ($zone['country'] !== $country) {
                continue;
            }
            $zoneDept = $this->fold($zone['department']);
            $zoneArea = $this->fold($zone['area']);
            if ($zoneDept !== '' && $zoneDept !== $department) {
                continue;
            }
            if ($zoneArea !== '' && $zoneArea !== $area) {
                continue;
            }
            $score = 1 + ($zoneDept !== '' ? 2 : 0) + ($zoneArea !== '' ? 3 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $zone;
            }
        }

        if ($best !== null) {
            $label = $best['label'] !== '' ? $best['label'] : $this->zoneName($best);

            return [
                'fee' => (float) $best['fee'],
                'eta_days' => $best['eta_days'],
                'zone' => $label,
            ];
        }

        $originCountry = (string) $config['origin_country'];
        $originDept = $this->fold((string) $config['origin_department']);
        $originArea = $this->fold((string) $config['origin_area']);

        if ($country !== '' && $originCountry !== '' && $country !== $originCountry) {
            return [
                'fee' => (float) $config['international_fee'],
                'eta_days' => (int) $config['international_days'],
                'zone' => 'Internacional',
            ];
        }

        if ($department !== '' && $originDept !== '' && $department !== $originDept) {
            return [
                'fee' => (float) $config['national_fee'],
                'eta_days' => (int) $config['national_days'],
                'zone' => 'Nacional (otro departamento)',
            ];
        }

        if ($area !== '' && $originArea !== '' && $area === $originArea) {
            return [
                'fee' => (float) $config['local_fee'],
                'eta_days' => (int) $config['local_days'],
                'zone' => 'Área local',
            ];
        }

        return [
            'fee' => (float) $config['department_fee'],
            'eta_days' => (int) $config['department_days'],
            'zone' => 'Mismo departamento',
        ];
    }

    /**
     * @param  array{country: string, department: string, area: string}  $zone
     */
    private function zoneName(array $zone): string
    {
        $parts = array_filter([$zone['country'], $zone['department'], $zone['area']]);

        return implode(' · ', $parts);
    }

    private function fold(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
