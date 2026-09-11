<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Models\User;
use App\Modules\Organization\Models\Organization;

class ClosingTimezone
{
    public function forUser(User $user): string
    {
        $user->loadMissing(['organization', 'sponsor']);

        $country = strtoupper((string) ($user->country ?: $user->sponsor?->country ?: ''));
        $fromCountry = $this->forCountry($country);

        if ($fromCountry !== null) {
            return $fromCountry;
        }

        $fromOrganization = $user->organization?->default_timezone;

        if (filled($fromOrganization) && $this->isValid((string) $fromOrganization)) {
            return (string) $fromOrganization;
        }

        return $this->default();
    }

    public function forCountry(?string $countryCode): ?string
    {
        $code = strtoupper(trim((string) $countryCode));
        if ($code === '' || $code === 'XX') {
            return null;
        }

        $map = config('rexmlm.closing.country_timezones', []);
        $timezone = $map[$code] ?? null;

        return is_string($timezone) && $this->isValid($timezone) ? $timezone : null;
    }

    public function default(): string
    {
        $timezone = (string) config('rexmlm.closing.default_timezone', 'America/La_Paz');

        return $this->isValid($timezone) ? $timezone : 'America/La_Paz';
    }

    public function label(string $timezone): string
    {
        $names = [
            'America/La_Paz' => 'La Paz (Bolivia)',
            'America/Caracas' => 'Caracas (Venezuela)',
            'America/Bogota' => 'Bogotá',
            'America/Mexico_City' => 'Ciudad de México',
            'America/Lima' => 'Lima',
            'America/Guayaquil' => 'Guayaquil',
            'America/Santiago' => 'Santiago',
            'America/Argentina/Buenos_Aires' => 'Buenos Aires',
            'America/Montevideo' => 'Montevideo',
            'America/Asuncion' => 'Asunción',
            'America/Sao_Paulo' => 'São Paulo',
            'America/Panama' => 'Panamá',
            'America/Costa_Rica' => 'Costa Rica',
            'America/Guatemala' => 'Guatemala',
            'America/Tegucigalpa' => 'Tegucigalpa',
            'America/El_Salvador' => 'El Salvador',
            'America/Managua' => 'Managua',
            'America/Santo_Domingo' => 'Santo Domingo',
            'America/Puerto_Rico' => 'Puerto Rico',
            'America/Havana' => 'La Habana',
            'America/New_York' => 'Nueva York',
            'Europe/Madrid' => 'Madrid',
            'Europe/Rome' => 'Roma',
            'Europe/Lisbon' => 'Lisboa',
        ];

        return $names[$timezone] ?? str_replace('_', ' ', $timezone);
    }

    public function snapshot(User $user): array
    {
        $timezone = $this->forUser($user);
        $organization = $user->workingOrganization();

        return [
            'timezone' => $timezone,
            'timezone_label' => $this->label($timezone),
            'country' => $user->country,
            'scope' => 'own_network',
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ] : null,
        ];
    }

    private function isValid(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }
}
