<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\OrderStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaderPosSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_leader_registers_in_person_sale_without_ecommerce(): void
    {
        $ana = $this->leader('ana-pos@inv.test');
        Sanctum::actingAs($ana);
        $product = $ana->store->products()->create([
            'name' => 'Mate verde',
            'price' => 55,
            'stock' => 10,
            'is_active' => true,
            'is_published' => false,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/my-store/orders', [
            'customer_name' => 'Luis Pérez',
            'customer_phone' => '70000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery' => 'pickup',
        ])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'pos')
            ->assertJsonPath('data.delivery', 'pickup')
            ->assertJsonPath('data.customer_name', 'Luis Pérez')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.shipping_fee', '0.00')
            ->assertJsonPath('data.shipping_zone', 'Retiro')
            ->assertJsonPath('data.total', '110.00');

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(1, $ana->store->orders()->where('channel', 'pos')->count());
    }

    public function test_pos_shipping_uses_the_same_dropshipping_fees(): void
    {
        $ana = $this->leader('ana-pos-ship@inv.test');
        $ana->store->update([
            'settings' => [
                'dropshipping' => [
                    'enabled' => true,
                    'origin_country' => 'BO',
                    'origin_department' => 'La Paz',
                    'origin_area' => 'El Alto',
                    'handling_fee' => 2,
                    'local_fee' => 5,
                    'department_fee' => 10,
                    'national_fee' => 20,
                    'international_fee' => 50,
                    'zones' => [],
                ],
            ],
        ]);
        Sanctum::actingAs($ana);
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 40,
            'stock' => 5,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/my-store/orders', [
            'customer_name' => 'María',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery' => 'shipping',
            'shipping_country' => 'BO',
            'shipping_department' => 'La Paz',
            'shipping_area' => 'El Alto',
            'shipping_address' => 'Calle 1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.delivery', 'shipping')
            ->assertJsonPath('data.shipping_fee', '7.00')
            ->assertJsonPath('data.total', '47.00')
            ->assertJsonPath('data.shipping_address', 'Calle 1');
    }

    public function test_pos_shipping_requires_country_when_dropshipping_is_on(): void
    {
        $ana = $this->leader('ana-pos-need-ship@inv.test');
        $ana->store->update([
            'settings' => [
                'dropshipping' => [
                    'enabled' => true,
                    'origin_country' => 'BO',
                    'local_fee' => 5,
                    'handling_fee' => 0,
                    'zones' => [],
                ],
            ],
        ]);
        Sanctum::actingAs($ana);
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 10,
            'stock' => 3,
            'is_active' => true,
            'source' => ProductSource::Personal,
        ]);

        $this->postJson('/api/v1/my-store/orders', [
            'customer_name' => 'María',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery' => 'shipping',
        ])->assertUnprocessable();
    }

    public function test_unpublished_product_cannot_be_sold_on_public_checkout(): void
    {
        $ana = $this->leader('ana-pos-pub@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Oculto',
            'price' => 10,
            'stock' => 3,
            'is_active' => true,
            'is_published' => false,
            'source' => ProductSource::Personal,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_pos_sale_can_stay_pending(): void
    {
        $ana = $this->leader('ana-pos-pend@inv.test');
        Sanctum::actingAs($ana);
        $product = $ana->store->products()->create([
            'name' => 'Té',
            'price' => 20,
            'stock' => 4,
            'is_active' => true,
            'source' => ProductSource::Personal,
        ]);

        $this->postJson('/api/v1/my-store/orders', [
            'customer_name' => 'Ana',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery' => 'pickup',
            'mark_paid' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $this->assertSame(3, $product->fresh()->stock);
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
            'slug' => 'net-pos-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-pos-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
