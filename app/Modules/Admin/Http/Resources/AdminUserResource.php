<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Resources;

use App\Services\Catalog\CatalogCompanyNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $names = app(CatalogCompanyNames::class);
        $companyId = $this->catalog_company_id ? (int) $this->catalog_company_id : null;
        $memberships = $this->relationLoaded('companyMemberships')
            ? $this->companyMemberships
            : collect();

        $companies = $memberships->map(function ($row) use ($names) {
            $id = (int) $row->catalog_company_id;

            return [
                'catalog_company_id' => $id,
                'catalog_company_name' => $names->name($id, $row->catalog_company_name),
                'catalog_rank_id' => $row->catalog_rank_id,
                'catalog_rank_name' => $row->catalog_rank_name,
                'is_primary' => (bool) $row->is_primary,
            ];
        })
            ->sortBy([
                fn (array $row) => $row['is_primary'] ? 0 : 1,
                fn (array $row) => $row['catalog_company_id'],
            ])
            ->values();

        if ($companies->isEmpty() && $companyId) {
            $companies = collect([[
                'catalog_company_id' => $companyId,
                'catalog_company_name' => $names->name($companyId, $this->catalog_company_name),
                'catalog_rank_id' => $this->catalog_rank_id,
                'catalog_rank_name' => $this->catalog_rank_name,
                'is_primary' => true,
            ]]);
        }

        $primary = $companies->firstWhere('is_primary', true) ?? $companies->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'country' => $this->country,
            'catalog_company_id' => $primary['catalog_company_id'] ?? $companyId,
            'catalog_company_name' => $primary['catalog_company_name'] ?? $names->name($companyId, $this->catalog_company_name),
            'catalog_rank_id' => $primary['catalog_rank_id'] ?? $this->catalog_rank_id,
            'catalog_rank_name' => $primary['catalog_rank_name'] ?? $this->catalog_rank_name,
            'organization_id' => $this->organization_id,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles),
            'referrals_count' => $this->whenCounted('referrals'),
            'created_at' => $this->created_at,
            'companies' => $companies,
            'store' => $this->whenLoaded('store'),
            'landing_page' => $this->whenLoaded('landingPage'),
            'referrals' => $this->whenLoaded('referrals'),
        ];
    }
}
