<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncentiveInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_sale_adds_incentive_cost_and_decrements_gift_stock(): void
    {
        $ana = $this->leader();
        Sanctum::actingAs($ana);

        $gift = $ana->store->products()->create([
            'name' => 'Termo',
            'price' => 0,
            'purchase_cost' => 8,
            'stock' => 5,
            'is_active' => true,
            'is_published' => false,
            'source' => ProductSource::Incentive,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $product = $ana->store->products()->create([
            'name' => 'Té verde',
            'price' => 55,
            'purchase_cost' => 30,
            'incentive_product_id' => $gift->id,
            'incentive_qty' => 1,
            'stock' => 4,
            'is_active' => true,
            'is_published' => false,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/my-store/orders', [
            'customer_name' => 'Luis',
            'customer_phone' => '70000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery' => 'pickup',
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_cost', '38.00')
            ->assertJsonPath('data.items.0.incentive_cost', '8.00')
            ->assertJsonPath('data.items.0.line_profit', '34.00');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(3, $gift->fresh()->stock);
    }

    private function leader(): User
    {
        $user = User::factory()->create(['email' => 'ana-inc@inv.test', 'country' => 'BO']);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-inc-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-inc-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
