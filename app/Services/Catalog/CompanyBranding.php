<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\User;
use Throwable;

class CompanyBranding
{
    /**
     * @return array{id: ?int, name: ?string, logo: ?string, color_palette: ?array<string, mixed>, rank_name: ?string, country: ?string}|null
     */
    public static function forUser(?User $user, int $depth = 0): ?array
    {
        if ($user === null) {
            return null;
        }

        $companyId = $user->workingCatalogCompanyId();

        if (! $companyId && ! $user->catalog_company_name) {
            if ($depth >= 6 || ! $user->sponsor_user_id || (int) $user->sponsor_user_id === (int) $user->id) {
                return null;
            }

            $sponsor = $user->relationLoaded('sponsor')
                ? $user->sponsor
                : User::query()
                    ->select(['id', 'sponsor_user_id', 'country', 'catalog_company_id', 'catalog_company_name', 'catalog_rank_name'])
                    ->find($user->sponsor_user_id);

            $brand = self::forUser($sponsor, $depth + 1);

            if ($brand === null) {
                return null;
            }

            $brand['rank_name'] = $user->catalog_rank_name;
            $brand['country'] = $user->country ?: $brand['country'];

            return $brand;
        }

        $fromCatalog = null;
        $membership = $companyId ? $user->membershipForCompany($companyId) : null;

        if ($companyId) {
            try {
                $fromCatalog = app(CatalogClient::class)->brandingFor($companyId);
            } catch (Throwable) {
                $fromCatalog = null;
            }
        }

        return [
            'id' => $companyId,
            'name' => $fromCatalog['name'] ?? $membership?->catalog_company_name ?? $user->catalog_company_name,
            'logo' => $fromCatalog['logo'] ?? null,
            'color_palette' => $fromCatalog['color_palette'] ?? null,
            'rank_name' => $membership?->catalog_rank_name ?? $user->catalog_rank_name,
            'country' => $user->country,
        ];
    }
}
