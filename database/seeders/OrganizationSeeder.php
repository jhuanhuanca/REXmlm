<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $sync = app(SyncUserOrganization::class);

        Organization::query()->firstOrCreate(
            ['slug' => config('rexmlm.closing.first_organization_slug', 'hgw')],
            [
                'name' => 'HGW',
                'default_timezone' => config('rexmlm.closing.default_timezone', 'America/La_Paz'),
                'default_currency' => 'USD',
                'status' => 'active',
            ],
        );
        // DXN, Face Global, Omnilife u otra: misma tabla, se crea en sasadmin al vincular catalog_company_id.

        User::query()
            ->where(function ($query) {
                $query->whereNotNull('catalog_company_id')
                    ->orWhereNotNull('catalog_company_name')
                    ->orWhereNotNull('organization_id');
            })
            ->orderBy('id')
            ->each(function (User $user) use ($sync): void {
                try {
                    $sync->handle($user);
                } catch (ValidationException) {
                    // 1:1: si ya está en otra org, se deja como está.
                }
            });
    }
}
