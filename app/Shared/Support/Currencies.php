<?php

declare(strict_types=1);

namespace App\Shared\Support;

final class Currencies
{
    public const COMMISSION = 'USD';

    /** @var list<string> */
    public const CODES = [
        'USD', 'EUR', 'BOB', 'VES', 'COP', 'MXN', 'PEN', 'CLP', 'ARS', 'UYU',
        'PYG', 'BRL', 'GTQ', 'HNL', 'NIO', 'CRC', 'PAB', 'DOP', 'CUP',
    ];

    /** @var array<string, string> */
    private const BY_COUNTRY = [
        'BO' => 'BOB',
        'VE' => 'VES',
        'CO' => 'COP',
        'MX' => 'MXN',
        'PE' => 'PEN',
        'EC' => 'USD',
        'CL' => 'CLP',
        'AR' => 'ARS',
        'UY' => 'UYU',
        'PY' => 'PYG',
        'BR' => 'BRL',
        'PA' => 'USD',
        'CR' => 'CRC',
        'GT' => 'GTQ',
        'HN' => 'HNL',
        'SV' => 'USD',
        'NI' => 'NIO',
        'DO' => 'DOP',
        'PR' => 'USD',
        'CU' => 'CUP',
        'US' => 'USD',
        'ES' => 'EUR',
        'IT' => 'EUR',
        'PT' => 'EUR',
    ];

    public static function isValid(?string $code): bool
    {
        return in_array(strtoupper(trim((string) $code)), self::CODES, true);
    }

    public static function normalize(?string $code, string $fallback = self::COMMISSION): string
    {
        $value = strtoupper(trim((string) $code));

        if (self::isValid($value)) {
            return $value;
        }

        return self::isValid($fallback) ? strtoupper($fallback) : self::COMMISSION;
    }

    public static function forCountry(?string $country): string
    {
        $code = strtoupper(trim((string) $country));

        return self::BY_COUNTRY[$code] ?? self::COMMISSION;
    }
}
