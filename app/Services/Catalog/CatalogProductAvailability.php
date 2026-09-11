<?php

declare(strict_types=1);

namespace App\Services\Catalog;

final class CatalogProductAvailability
{
    /**
     * @param  array<string, mixed>  $product
     */
    public static function matches(array $product, ?string $country): bool
    {
        $codes = $product['countries'] ?? null;

        if (! is_array($codes) || $codes === []) {
            return true;
        }

        $code = strtoupper(trim((string) $country));

        if ($code === '') {
            return true;
        }

        $normalized = array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            $codes,
        );

        return in_array($code, $normalized, true);
    }
}
