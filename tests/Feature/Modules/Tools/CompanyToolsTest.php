<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Tools;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyToolsTest extends TestCase
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
        ]);
    }

    public function test_imc_packages_use_company_catalog_and_country(): void
    {
        Http::fake([
            'catalog.test/api/v1/companies/7/imc-packages*' => Http::response([
                'data' => [[
                    'id' => 11,
                    'goal' => 'lose_weight',
                    'name' => 'Kit bajar Scentia',
                    'description' => 'Productos de la marca',
                    'items' => [
                        [
                            'notes' => '1 taza al día',
                            'product' => [
                                'id' => 1,
                                'name' => 'Té Scentia',
                                'is_active' => true,
                                'countries' => ['BO'],
                            ],
                        ],
                        [
                            'notes' => 'Solo Perú',
                            'product' => [
                                'id' => 2,
                                'name' => 'Té slimming HGW',
                                'is_active' => true,
                                'countries' => ['PE'],
                            ],
                        ],
                    ],
                ]],
            ], 200),
            'catalog.test/api/v1/companies/7' => Http::response([
                'data' => ['id' => 7, 'name' => 'Scentia', 'logo' => null, 'color_palette' => null],
            ], 200),
        ]);

        $leader = $this->leader('ana-tools@inv.test', 7);
        Sanctum::actingAs($leader);

        $this->getJson('/api/v1/tools/imc-packages')
            ->assertOk()
            ->assertJsonPath('company.name', 'Scentia')
            ->assertJsonPath('data.0.title', 'Kit bajar Scentia')
            ->assertJsonPath('data.0.products.0.name', 'Té Scentia')
            ->assertJsonMissing(['name' => 'Té slimming HGW']);
    }

    public function test_imc_package_stays_listed_when_products_are_for_another_country(): void
    {
        Http::fake([
            'catalog.test/api/v1/companies/7/imc-packages*' => Http::response([
                'data' => [[
                    'id' => 1,
                    'goal' => 'lose_weight',
                    'name' => 'OMEGA 360',
                    'description' => 'Kit de FWP',
                    'items' => [[
                        'notes' => null,
                        'product' => [
                            'id' => 5,
                            'name' => 'OMEGA360',
                            'is_active' => true,
                            'countries' => ['PE'],
                        ],
                    ]],
                ]],
            ], 200),
            'catalog.test/api/v1/companies/7' => Http::response([
                'data' => ['id' => 7, 'name' => 'FWP'],
            ], 200),
        ]);

        $leader = $this->leader('ana-omega@inv.test', 7);
        Sanctum::actingAs($leader);

        $this->getJson('/api/v1/tools/imc-packages')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'OMEGA 360')
            ->assertJsonPath('data.0.goal', 'lose_weight')
            ->assertJsonPath('data.0.products', []);
    }

    public function test_wellness_needs_come_from_the_company_catalog(): void
    {
        Http::fake([
            'catalog.test/api/v1/companies/7/wellness-needs*' => Http::response([
                'data' => [[
                    'id' => 4,
                    'name' => 'Estrés',
                    'description' => 'Apoyo de la marca',
                    'items' => [[
                        'notes' => '2 cápsulas',
                        'product' => [
                            'id' => 9,
                            'name' => 'Relax Scentia',
                            'is_active' => true,
                            'countries' => [],
                        ],
                    ]],
                ]],
            ], 200),
            'catalog.test/api/v1/companies/7' => Http::response([
                'data' => ['id' => 7, 'name' => 'Scentia'],
            ], 200),
        ]);

        $leader = $this->leader('ana-well@inv.test', 7);
        Sanctum::actingAs($leader);

        $this->getJson('/api/v1/tools/wellness-needs')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Estrés')
            ->assertJsonPath('data.0.products.0.name', 'Relax Scentia');
    }

    public function test_documents_come_from_the_company_catalog(): void
    {
        Http::fake([
            'catalog.test/api/v1/companies/7/documents*' => Http::response([
                'data' => [[
                    'id' => 3,
                    'title' => 'Bienvenida',
                    'file_path' => 'https://ejemplo.com/audio.mp3',
                    'url' => 'https://ejemplo.com/audio.mp3',
                    'file_type' => 'audio',
                    'kind' => 'audio',
                    'player' => 'audio',
                    'embed_url' => null,
                    'is_active' => true,
                ]],
            ], 200),
            'catalog.test/api/v1/companies/7' => Http::response([
                'data' => ['id' => 7, 'name' => 'Scentia'],
            ], 200),
        ]);

        $leader = $this->leader('ana-docs@inv.test', 7);
        Sanctum::actingAs($leader);

        $this->getJson('/api/v1/tools/documents?kind=audio')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Bienvenida')
            ->assertJsonPath('data.0.player', 'audio')
            ->assertJsonPath('data.0.url', 'https://ejemplo.com/audio.mp3');
    }

    private function leader(string $email, int $companyId): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'catalog_company_id' => $companyId,
            'catalog_company_name' => 'Scentia',
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-tools-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();

        return $user->fresh();
    }
}
