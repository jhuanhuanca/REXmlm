<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\Landing\Models\LandingPage;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepareLoadTestStoreCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_prepare_command_creates_public_storefront(): void
    {
        $this->artisan('store:prepare-load-test --json')
            ->assertOk();

        $store = Store::query()->where('slug', 'loadtest-shop')->first();
        $this->assertNotNull($store);
        $this->assertTrue($store->is_active);

        $product = Product::query()->where('store_id', $store->id)->where('slug', 'loadtest-caja')->first();
        $this->assertNotNull($product);
        $this->assertSame(1_000_000, $product->stock);

        $this->assertTrue(LandingPage::query()->where('slug', 'loadtest-landing')->where('is_published', true)->exists());
        $this->assertTrue(User::query()->where('email', 'loadtest-shop@rexmlm.invalid')->exists());

        $this->getJson('/api/v1/store/loadtest-shop')->assertOk();
        $this->getJson('/api/v1/store/loadtest-shop/products/loadtest-caja')->assertOk();
        $this->getJson('/api/v1/landing/loadtest-landing')->assertOk();
    }

    public function test_prepare_command_refuses_production_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('store:prepare-load-test')
            ->assertFailed();
    }
}
