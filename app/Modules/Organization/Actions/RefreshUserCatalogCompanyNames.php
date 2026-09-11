<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Services\Catalog\CatalogCompanyNames;

class RefreshUserCatalogCompanyNames
{
    public function handle(): int
    {
        $names = app(CatalogCompanyNames::class)->all();
        $sync = app(SyncUserOrganization::class);
        $updated = 0;

        User::query()
            ->with('companyMemberships')
            ->where(function ($query) {
                $query->whereNotNull('catalog_company_id')
                    ->orWhereHas('companyMemberships');
            })
            ->orderBy('id')
            ->each(function (User $user) use ($names, $sync, &$updated): void {
                $changed = false;
                $primaryId = $user->catalog_company_id ? (int) $user->catalog_company_id : null;
                $primaryName = $primaryId ? ($names[$primaryId] ?? null) : null;

                if ($primaryId && filled($primaryName) && $user->catalog_company_name !== $primaryName) {
                    $user->catalog_company_name = $primaryName;
                    $changed = true;
                }

                foreach ($user->companyMemberships as $membership) {
                    $id = (int) $membership->catalog_company_id;
                    $name = $names[$id] ?? null;
                    if (! filled($name) || $membership->catalog_company_name === $name) {
                        continue;
                    }

                    $membership->forceFill(['catalog_company_name' => $name])->save();
                    $changed = true;
                }

                if ($changed) {
                    $user->save();
                    $updated++;
                }

                if ($primaryId && filled($primaryName)) {
                    $organization = $sync->handle($user, $primaryId, $primaryName);
                    if ($organization && (int) $organization->catalog_company_id === $primaryId && (int) $user->organization_id !== (int) $organization->id) {
                        $user->forceFill(['organization_id' => $organization->id])->save();
                    }
                }
            });

        return $updated;
    }
}
