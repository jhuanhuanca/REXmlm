<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationUser;
use Illuminate\Support\Str;

class SyncUserOrganization
{
    public function handle(User $user, ?int $catalogCompanyId = null, ?string $catalogCompanyName = null): ?Organization
    {
        $catalogCompanyId = $catalogCompanyId ?: $user->catalog_company_id;
        $catalogCompanyName = filled($catalogCompanyName) ? $catalogCompanyName : $user->catalog_company_name;

        if (! $catalogCompanyId && blank($catalogCompanyName)) {
            return $user->organization;
        }

        $organization = $this->findOrCreate(
            $catalogCompanyId ? (int) $catalogCompanyId : null,
            $catalogCompanyName ? (string) $catalogCompanyName : null,
        );

        $this->attach($user, $organization);

        return $organization;
    }

    public function attach(User $user, Organization $organization, bool $asPrimary = false): void
    {
        OrganizationUser::query()->firstOrCreate([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ]);

        if ($asPrimary || ! $user->organization_id) {
            $user->forceFill([
                'organization_id' => $organization->id,
                'catalog_company_id' => $user->catalog_company_id ?: $organization->catalog_company_id,
                'catalog_company_name' => $user->catalog_company_name ?: $organization->name,
            ])->save();
        }
    }

    public function findOrCreate(?int $catalogCompanyId, ?string $name): Organization
    {
        if ($catalogCompanyId) {
            $existing = Organization::query()->where('catalog_company_id', $catalogCompanyId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $slug = $this->slugFor($name, $catalogCompanyId);
        $bySlug = Organization::query()->where('slug', $slug)->first();
        if ($bySlug) {
            $slugCompanyId = $bySlug->catalog_company_id ? (int) $bySlug->catalog_company_id : null;
            if ($catalogCompanyId && $slugCompanyId === null) {
                $bySlug->forceFill(['catalog_company_id' => $catalogCompanyId])->save();

                return $bySlug;
            }
            if ($catalogCompanyId && $slugCompanyId === $catalogCompanyId) {
                return $bySlug;
            }
            if ($catalogCompanyId && $slugCompanyId !== $catalogCompanyId) {
                $slug = $this->uniqueSlug($name, $catalogCompanyId);
            } else {
                return $bySlug;
            }
        }

        return Organization::query()->create([
            'catalog_company_id' => $catalogCompanyId,
            'name' => $name ?: ($catalogCompanyId ? 'Empresa #'.$catalogCompanyId : strtoupper($slug)),
            'slug' => $slug,
            'default_timezone' => config('rexmlm.closing.default_timezone', 'America/La_Paz'),
            'default_currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function slugFor(?string $name, ?int $catalogCompanyId = null): string
    {
        $slug = Str::slug((string) $name);

        if ($slug !== '') {
            return $slug;
        }

        if ($catalogCompanyId) {
            return 'empresa-'.$catalogCompanyId;
        }

        return (string) config('rexmlm.closing.first_organization_slug', 'hgw');
    }

    private function uniqueSlug(?string $name, ?int $catalogCompanyId): string
    {
        $base = $this->slugFor($name, $catalogCompanyId);
        $slug = $catalogCompanyId ? $base.'-'.$catalogCompanyId : $base.'-'.Str::lower(Str::random(4));

        if (! Organization::query()->where('slug', $slug)->exists()) {
            return $slug;
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
