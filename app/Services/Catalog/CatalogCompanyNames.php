<?php

declare(strict_types=1);

namespace App\Services\Catalog;

class CatalogCompanyNames
{
    /** @var array<int, string>|null */
    private ?array $names = null;

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        if ($this->names === null) {
            $this->names = app(CatalogClient::class)->companyNamesById();
        }

        return $this->names;
    }

    public function name(?int $companyId, ?string $fallback = null): ?string
    {
        if (! $companyId) {
            return $fallback;
        }

        $fromCatalog = $this->all()[$companyId] ?? null;

        return filled($fromCatalog) ? $fromCatalog : $fallback;
    }
}
