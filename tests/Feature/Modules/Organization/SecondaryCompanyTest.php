<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Organization;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Organization\Models\UserCompanyMembership;
use App\Modules\Store\Models\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecondaryCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'cashier.secret' => null,
            'services.catalog.url' => 'http://catalog.test/api/v1',
            'services.catalog.token' => 'test-token',
            'services.catalog.cache_ttl' => 0,
            'rexmlm.secondary_company.price' => 9.9,
        ]);
    }

    public function test_leader_can_add_secondary_company_and_switch_context(): void
    {
        Http::fake(function ($request) {
            if (preg_match('#/companies/(\d+)#', $request->url(), $match)) {
                $id = (int) $match[1];

                return Http::response([
                    'data' => [
                        'id' => $id,
                        'name' => $id === 9 ? 'FWP' : 'HGW',
                        'logo' => null,
                        'color_palette' => null,
                    ],
                ], 200);
            }

            return Http::response([
                'data' => [
                    ['id' => 7, 'name' => 'HGW'],
                    ['id' => 9, 'name' => 'FWP'],
                ],
            ], 200);
        });

        $ana = $this->leader('ana-multi@co.test', 7, 'HGW');
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/my-companies', [
            'catalog_company_id' => 9,
            'catalog_rank_name' => 'Builder',
        ])->assertCreated()
            ->assertJsonPath('data.active_catalog_company_id', 9)
            ->assertJsonPath('data.catalog_company_id', 7);

        $this->assertSame(2, UserCompanyMembership::query()->where('user_id', $ana->id)->count());
        $this->assertTrue(
            UserCompanyMembership::query()
                ->where('user_id', $ana->id)
                ->where('catalog_company_id', 9)
                ->where('is_primary', false)
                ->exists()
        );

        $this->putJson('/api/v1/my-companies/active', [
            'catalog_company_id' => 7,
        ])->assertOk()
            ->assertJsonPath('data.active_catalog_company_id', 7)
            ->assertJsonPath('data.company.name', 'HGW');
    }

    public function test_cannot_add_the_same_company_twice(): void
    {
        $ana = $this->leader('ana-dup@co.test', 7, 'HGW');
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/my-companies', [
            'catalog_company_id' => 7,
        ])->assertUnprocessable();
    }

    private function leader(string $email, int $companyId, string $companyName): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'catalog_company_id' => $companyId,
            'catalog_company_name' => $companyName,
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-co-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-co-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
