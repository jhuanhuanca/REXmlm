<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Store;
use App\Shared\Support\Currencies;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_bolivia_store_defaults_to_bob_and_products_inherit_it(): void
    {
        $ana = $this->leader('ana-currency@inv.test');
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/my-store')
            ->assertOk()
            ->assertJsonPath('data.currency', 'BOB');

        $this->postJson('/api/v1/products', [
            'name' => 'Té local',
            'price' => 80,
            'stock' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'BOB');
    }

    public function test_leader_can_set_store_currency_and_product_currency(): void
    {
        $ana = $this->leader('ana-mxn@inv.test');
        Sanctum::actingAs($ana);

        $this->putJson('/api/v1/my-store', [
            'settings' => ['currency' => 'MXN'],
        ])->assertOk()->assertJsonPath('data.currency', 'MXN');

        $this->postJson('/api/v1/products', [
            'name' => 'Serum',
            'price' => 350,
            'stock' => 2,
            'currency' => 'MXN',
        ])->assertCreated()->assertJsonPath('data.currency', 'MXN');
    }

    public function test_order_rejects_mixed_currencies_and_keeps_commissions_in_usd(): void
    {
        $ana = $this->leader('ana-mix@inv.test');
        $bob = $ana->store->products()->create([
            'name' => 'Local',
            'price' => 50,
            'stock' => 5,
            'currency' => 'BOB',
            'source' => ProductSource::Personal,
            'is_active' => true,
            'is_published' => true,
        ]);
        $usd = $ana->store->products()->create([
            'name' => 'Dollar',
            'price' => 10,
            'stock' => 5,
            'currency' => 'USD',
            'source' => ProductSource::Personal,
            'is_active' => true,
            'is_published' => true,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Cliente',
            'customer_email' => 'cli@test.com',
            'items' => [
                ['product_id' => $bob->id, 'quantity' => 1],
                ['product_id' => $usd->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Cliente',
            'customer_email' => 'cli@test.com',
            'items' => [
                ['product_id' => $bob->id, 'quantity' => 1],
            ],
        ])->assertCreated()->assertJsonPath('data.currency', 'BOB');

        $this->assertSame('USD', Currencies::COMMISSION);
    }

    private function leader(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-cur-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-cur-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
