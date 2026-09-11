<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Services\Catalog\CatalogCompanyNames;
use App\Services\Catalog\CompanyBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
            'current_network_id' => $this->current_network_id,
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'country' => $this->country,
            'catalog_company_id' => $this->catalog_company_id,
            'catalog_company_name' => app(CatalogCompanyNames::class)->name(
                $this->catalog_company_id ? (int) $this->catalog_company_id : null,
                $this->catalog_company_name,
            ),
            'catalog_rank_id' => $this->catalog_rank_id,
            'catalog_rank_name' => $this->catalog_rank_name,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
            ] : null),
            'company' => CompanyBranding::forUser($this->resource),
            'active_catalog_company_id' => $this->workingCatalogCompanyId(),
            'companies' => $this->whenLoaded('companyMemberships', function () {
                $names = app(CatalogCompanyNames::class);

                return $this->companyMemberships->map(function ($row) use ($names) {
                    $id = (int) $row->catalog_company_id;

                    return [
                        'catalog_company_id' => $id,
                        'catalog_company_name' => $names->name($id, $row->catalog_company_name),
                        'catalog_rank_id' => $row->catalog_rank_id,
                        'catalog_rank_name' => $row->catalog_rank_name,
                        'is_primary' => (bool) $row->is_primary,
                    ];
                })->values();
            }),
            'secondary_company_price' => (float) config('rexmlm.secondary_company.price', 9.9),
            'secondary_company_currency' => config('rexmlm.secondary_company.currency', 'USD'),
            'store' => $this->whenLoaded('store'),
            'landing_page' => $this->whenLoaded('landingPage'),
            'network' => $this->whenLoaded('currentNetwork'),
            'sponsor' => $this->when(
                $this->relationLoaded('sponsor') && $this->sponsor,
                fn () => [
                    'id' => $this->sponsor->id,
                    'name' => $this->sponsor->name,
                    'store' => $this->sponsor->relationLoaded('store') && $this->sponsor->store
                        ? [
                            'slug' => $this->sponsor->store->slug,
                            'name' => $this->sponsor->store->name,
                            'is_active' => $this->sponsor->store->is_active,
                        ]
                        : null,
                    'landing_page' => $this->sponsor->relationLoaded('landingPage') && $this->sponsor->landingPage
                        ? [
                            'slug' => $this->sponsor->landingPage->slug,
                            'title' => $this->sponsor->landingPage->title,
                            'is_published' => $this->sponsor->landingPage->is_published,
                        ]
                        : null,
                ],
            ),
        ];
    }
}
