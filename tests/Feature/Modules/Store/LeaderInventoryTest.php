<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Notifications\InventoryAlertNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaderInventoryTest extends TestCase
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

    public function test_personal_product_stays_in_the_leader_store_only(): void
    {
        $ana = $this->leader('ana@inv.test', 7);
        $mei = $this->leader('mei@inv.test', 7);

        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/products', [
            'name' => 'Té verde propio',
            'price' => 55,
            'stock' => 10,
            'description' => 'Inventario de Ana',
        ])->assertCreated()
            ->assertJsonPath('data.source', 'personal');

        $this->assertSame(1, $ana->store->products()->count());
        $this->assertSame(0, $mei->store->products()->count());
    }

    public function test_company_catalog_arrives_unpublished_until_the_leader_chooses(): void
    {
        Http::fake([
            'catalog.test/*' => Http::response([
                'data' => [[
                    'id' => 88,
                    'name' => 'HGW Vita',
                    'price' => 20,
                    'description' => 'Cápsula',
                    'technical_sheet' => '1 al día',
                    'is_active' => true,
                ]],
            ], 200),
        ]);

        $ana = $this->leader('ana-cat@inv.test', 7);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/products?source=company')->assertOk();

        $product = $ana->store->products()->where('catalog_product_id', 88)->first();
        $this->assertNotNull($product);
        $this->assertSame(ProductSource::Company, $product->source);
        $this->assertFalse($product->is_published);

        $this->putJson('/api/v1/products/'.$product->id, [
            'is_published' => true,
            'stock' => 5,
            'fulfillment' => 'stock',
        ])->assertOk()->assertJsonPath('data.is_published', true);

        $this->getJson('/api/v1/store/'.$ana->store->slug)
            ->assertOk()
            ->assertJsonPath('data.products.0.name', 'HGW Vita');
    }

    public function test_company_catalog_skips_products_from_other_countries(): void
    {
        Http::fake([
            'catalog.test/*' => Http::response([
                'data' => [
                    [
                        'id' => 88,
                        'name' => 'Vita Bolivia',
                        'price' => 20,
                        'is_active' => true,
                        'countries' => ['BO'],
                    ],
                    [
                        'id' => 89,
                        'name' => 'Vita Peru',
                        'price' => 20,
                        'is_active' => true,
                        'countries' => ['PE'],
                    ],
                    [
                        'id' => 90,
                        'name' => 'Vita Global',
                        'price' => 20,
                        'is_active' => true,
                        'countries' => [],
                    ],
                ],
            ], 200),
        ]);

        $ana = $this->leader('ana-country@inv.test', 7);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/products?source=company')->assertOk();

        $this->assertTrue($ana->store->products()->where('catalog_product_id', 88)->exists());
        $this->assertFalse($ana->store->products()->where('catalog_product_id', 89)->exists());
        $this->assertTrue($ana->store->products()->where('catalog_product_id', 90)->exists());
    }

    public function test_unpublished_and_expired_products_are_hidden_from_the_public_store(): void
    {
        $ana = $this->leader('ana-pub@inv.test');
        $hidden = $ana->store->products()->create([
            'name' => 'Oculto',
            'price' => 10,
            'stock' => 4,
            'is_active' => true,
            'is_published' => false,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);
        $expired = $ana->store->products()->create([
            'name' => 'Vencido',
            'price' => 10,
            'stock' => 4,
            'is_active' => true,
            'is_published' => true,
            'expires_at' => now()->subDay()->toDateString(),
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);
        $visible = $ana->store->products()->create([
            'name' => 'A la venta',
            'price' => 12,
            'stock' => 4,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $response = $this->getJson('/api/v1/store/'.$ana->store->slug)->assertOk();
        $ids = collect($response->json('data.products'))->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->assertFalse($ids->contains($expired->id));
    }

    public function test_csv_import_creates_personal_inventory(): void
    {
        $ana = $this->leader('ana-csv@inv.test');
        Sanctum::actingAs($ana);

        $csv = implode("\n", [
            'nombre,precio,stock,descripcion,fulfillment,dropship_url',
            'Serum,80,0,Drop del proveedor,dropship,https://proveedor.test/serum',
            'Jabón,12,30,Stock propio,stock,',
        ]);

        $file = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertCreated()
            ->assertJsonPath('imported', 2);

        $this->assertSame(2, $ana->store->products()->where('source', 'personal')->count());
        $this->assertTrue(
            $ana->store->products()->where('name', 'Serum')->where('fulfillment', 'dropship')->exists()
        );
    }

    public function test_csv_import_accepts_excel_spanish_semicolon_and_utf8_bom(): void
    {
        $ana = $this->leader('ana-csv-excel@inv.test');
        Sanctum::actingAs($ana);

        $csv = "\xEF\xBB\xBF".implode("\r\n", [
            'sep=;',
            'nombre;precio;stock;descripcion;imagen;ficha_tecnica;vencimiento;activo;fulfillment;dropship_url;dropship_sku',
            'Té verde;55;100;Infusión diaria;https://ejemplo.com/te.jpg;1 taza al día;2027-12-31;1;stock;;',
            'Serum;80;0;Envío del proveedor;https://ejemplo.com/serum.jpg;2 gotas noche;;1;dropship;https://proveedor.com/serum;SKU-88',
        ]);

        $file = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertCreated()
            ->assertJsonPath('imported', 2);

        $tea = $ana->store->products()->where('name', 'Té verde')->first();
        $this->assertNotNull($tea);
        $this->assertSame('Infusión diaria', $tea->description);
        $this->assertSame('1 taza al día', $tea->technical_sheet);
        $this->assertSame('2027-12-31', $tea->expires_at?->toDateString());
        $this->assertTrue(
            $ana->store->products()->where('name', 'Serum')->where('fulfillment', 'dropship')->exists()
        );
    }

    public function test_leader_creates_own_categories_and_classifies_products(): void
    {
        $ana = $this->leader('ana-own-cat@inv.test');
        $mei = $this->leader('mei-own-cat@inv.test');
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/my-store/categories', ['name' => 'Infusiones'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Infusiones');

        $categoryId = (int) $ana->store->productCategories()->value('id');

        $this->postJson('/api/v1/products', [
            'name' => 'Mate verde',
            'price' => 55,
            'stock' => 10,
            'store_category_id' => $categoryId,
        ])
            ->assertCreated()
            ->assertJsonPath('data.store_category_id', $categoryId)
            ->assertJsonPath('data.category.name', 'Infusiones');

        $this->getJson('/api/v1/store/'.$ana->store->slug)
            ->assertOk()
            ->assertJsonPath('data.products.0.category.name', 'Infusiones');

        Sanctum::actingAs($mei);
        $this->getJson('/api/v1/my-store/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/my-store/categories/'.$categoryId, ['name' => 'Hack'])->assertNotFound();
        $this->postJson('/api/v1/products', [
            'name' => 'Otro',
            'price' => 1,
            'stock' => 1,
            'store_category_id' => $categoryId,
        ])->assertUnprocessable();
    }

    public function test_deleting_a_category_leaves_products_uncategorized(): void
    {
        $ana = $this->leader('ana-del-cat@inv.test');
        Sanctum::actingAs($ana);
        $category = $ana->store->productCategories()->create(['name' => 'Tés', 'slug' => 'tes', 'sort' => 1]);
        $product = $ana->store->products()->create([
            'name' => 'Té verde',
            'price' => 10,
            'stock' => 4,
            'source' => ProductSource::Personal,
            'store_category_id' => $category->id,
        ]);

        $this->deleteJson('/api/v1/my-store/categories/'.$category->id)
            ->assertOk();

        $this->assertNull($product->fresh()->store_category_id);
    }

    public function test_csv_import_creates_category_from_column(): void
    {
        $ana = $this->leader('ana-csv-cat@inv.test');
        Sanctum::actingAs($ana);

        $csv = implode("\n", [
            'nombre,precio,stock,categoria',
            'Mate verde,55,10,Infusiones',
            'Jabón,12,30,Infusiones',
        ]);
        $file = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertCreated()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('data.0.category.name', 'Infusiones');

        $this->assertSame(1, $ana->store->productCategories()->count());
        $this->assertSame(2, $ana->store->products()->whereNotNull('store_category_id')->count());
    }

    public function test_csv_import_rejects_non_csv_extension(): void
    {
        $ana = $this->leader('ana-csv-ext@inv.test');
        Sanctum::actingAs($ana);

        $file = UploadedFile::fake()->createWithContent('inventario.xlsx', "nombre,precio,stock\nA,1,1");

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertUnprocessable();
    }

    public function test_csv_import_rejects_too_many_rows(): void
    {
        $ana = $this->leader('ana-csv-max@inv.test');
        Sanctum::actingAs($ana);

        $lines = ['nombre,precio,stock'];
        for ($i = 0; $i < 501; $i++) {
            $lines[] = 'Prod '.$i.',10,1';
        }
        $file = UploadedFile::fake()->createWithContent('inventario.csv', implode("\n", $lines));

        $this->post('/api/v1/products/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, $ana->store->products()->count());
    }

    public function test_dropship_order_does_not_consume_stock_stock_order_does(): void
    {
        $ana = $this->leader('ana-ord@inv.test');
        $drop = $ana->store->products()->create([
            'name' => 'Drop',
            'price' => 10,
            'stock' => 0,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Dropship,
        ]);
        $stock = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 8,
            'stock' => 2,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [
                ['product_id' => $drop->id, 'quantity' => 3],
                ['product_id' => $stock->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->assertSame(0, $drop->fresh()->stock);
        $this->assertSame(1, $stock->fresh()->stock);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [['product_id' => $stock->id, 'quantity' => 5]],
        ])->assertUnprocessable();
    }

    public function test_dropship_product_with_units_decrements_stock(): void
    {
        $ana = $this->leader('ana-ds-units@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Caja empresa',
            'price' => 8,
            'stock' => 5,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Company,
            'fulfillment' => ProductFulfillment::Dropship,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_sale_notifies_when_stock_falls_below_three(): void
    {
        Notification::fake();
        $ana = $this->leader('ana-alert@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 8,
            'stock' => 3,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(2, $product->fresh()->stock);
        Notification::assertSentTo($ana, InventoryAlertNotification::class);
    }

    public function test_leader_inventory_alert_thresholds_come_from_store_settings(): void
    {
        $ana = $this->leader('ana-thresholds@inv.test');
        Sanctum::actingAs($ana);

        $this->putJson('/api/v1/my-store', [
            'settings' => [
                'inventory' => [
                    'low_stock_below' => 10,
                    'expiry_warning_days' => 3,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.inventory.low_stock_below', 10)
            ->assertJsonPath('data.inventory.expiry_warning_days', 3);

        $soon = $ana->store->products()->create([
            'name' => 'Cerca',
            'price' => 8,
            'stock' => 9,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
            'expires_at' => now()->addDays(2)->toDateString(),
        ]);
        $later = $ana->store->products()->create([
            'name' => 'Lejos',
            'price' => 8,
            'stock' => 20,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
            'expires_at' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertTrue($soon->fresh()->isLowStock());
        $this->assertTrue($soon->fresh()->isExpiringSoon());
        $this->assertFalse($later->fresh()->isExpiringSoon());
        $this->assertFalse($later->fresh()->isLowStock());

        $kinds = collect($this->getJson('/api/v1/notifications')->assertOk()->json('data'))->pluck('kind');
        $this->assertTrue($kinds->contains('low_stock'));
        $this->assertTrue($kinds->contains('expiring'));
    }

    public function test_expiring_product_shows_up_in_notifications(): void
    {
        $ana = $this->leader('ana-exp@inv.test');
        Sanctum::actingAs($ana);
        $ana->store->products()->create([
            'name' => 'Por vencer',
            'price' => 8,
            'stock' => 10,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
            'expires_at' => now()->addDays(5)->toDateString(),
        ]);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.kind', 'expiring');
    }

    public function test_leader_cannot_update_another_leaders_product(): void
    {
        $ana = $this->leader('ana-iso@inv.test');
        $mei = $this->leader('mei-iso@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Solo Ana',
            'price' => 9,
            'stock' => 3,
            'source' => ProductSource::Personal,
        ]);

        Sanctum::actingAs($mei);
        $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Hack'])->assertNotFound();
        $this->assertSame('Solo Ana', $product->fresh()->name);
    }

    private function leader(string $email, ?int $companyId = null): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'catalog_company_id' => $companyId,
            'catalog_company_name' => $companyId ? 'HGW' : null,
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-inv-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-inv-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
