<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\UserCompanyMembership;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserCompanyListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'services.catalog.url' => 'http://catalog.test/api/v1',
            'services.catalog.token' => 'test-token',
            'services.catalog.cache_ttl' => 0,
        ]);
    }

    public function test_admin_list_shows_catalog_company_name_not_stale_hgw_label(): void
    {
        Http::fake([
            'catalog.test/api/v1/companies' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'HGW'],
                    ['id' => 5, 'name' => 'OMNILIFE'],
                    ['id' => 6, 'name' => 'FWP'],
                ],
            ], 200),
        ]);

        $omnilife = User::factory()->create([
            'name' => 'Oscar',
            'email' => 'oscar@test.com',
            'catalog_company_id' => 5,
            'catalog_company_name' => 'HGW',
        ]);
        $omnilife->assignRole('leader');
        UserCompanyMembership::query()->create([
            'user_id' => $omnilife->id,
            'catalog_company_id' => 5,
            'catalog_company_name' => 'HGW',
            'is_primary' => true,
        ]);

        $fwp = User::factory()->create([
            'name' => 'Prueba FWP',
            'email' => 'fwp@test.com',
            'catalog_company_id' => 6,
            'catalog_company_name' => 'HGW',
        ]);
        $fwp->assignRole('leader');
        UserCompanyMembership::query()->create([
            'user_id' => $fwp->id,
            'catalog_company_id' => 6,
            'catalog_company_name' => 'HGW',
            'is_primary' => true,
        ]);
        UserCompanyMembership::query()->create([
            'user_id' => $fwp->id,
            'catalog_company_id' => 1,
            'catalog_company_name' => 'HGW',
            'is_primary' => false,
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/users?search=Oscar')
            ->assertOk()
            ->assertJsonPath('data.0.catalog_company_name', 'OMNILIFE')
            ->assertJsonPath('data.0.companies.0.catalog_company_name', 'OMNILIFE');

        $this->getJson('/api/v1/admin/users?search=Prueba')
            ->assertOk()
            ->assertJsonPath('data.0.catalog_company_name', 'FWP')
            ->assertJsonPath('data.0.companies.0.catalog_company_name', 'FWP')
            ->assertJsonPath('data.0.companies.1.catalog_company_name', 'HGW');

        $this->getJson('/api/v1/admin/users?catalog_company_id=6')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'fwp@test.com');
    }

    public function test_sync_does_not_attach_other_companies_to_the_hgw_organization(): void
    {
        $sync = app(SyncUserOrganization::class);
        $hgw = $sync->handle(User::factory()->create([
            'catalog_company_id' => 1,
            'catalog_company_name' => 'HGW',
        ]), 1, 'HGW');

        $omniUser = User::factory()->create([
            'catalog_company_id' => 5,
            'catalog_company_name' => 'HGW',
        ]);
        $omni = $sync->handle($omniUser, 5, 'HGW');

        $this->assertNotNull($hgw);
        $this->assertNotNull($omni);
        $this->assertNotSame($hgw->id, $omni->id);
        $this->assertSame(1, (int) $hgw->catalog_company_id);
        $this->assertSame(5, (int) $omni->catalog_company_id);
        $this->assertSame(1, Organization::query()->where('catalog_company_id', 5)->count());
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin-list@test.com']);
        $admin->assignRole('admin');

        return $admin;
    }
}
