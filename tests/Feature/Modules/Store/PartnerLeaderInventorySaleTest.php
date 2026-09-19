<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\MLM\Models\Referral;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\ReferralStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerLeaderInventorySaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_partner_cannot_see_leader_inventory_without_grant(): void
    {
        [, $socio] = $this->teamWithStock();
        Sanctum::actingAs($socio);

        $this->getJson('/api/v1/partner-sales')->assertForbidden();
        $this->postJson('/api/v1/partner-sales/orders', [
            'customer_name' => 'Luis',
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'delivery' => 'pickup',
        ])->assertForbidden();
    }

    public function test_leader_grants_partner_who_sells_from_warehouse(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);

        $referralId = $this->referralId($ana, $socio);

        $this->putJson('/api/v1/dashboard/team/'.$referralId, [
            'can_sell_inventory' => true,
        ])
            ->assertOk()
            ->assertJsonPath('can_sell_inventory', true);

        Sanctum::actingAs($socio->fresh());
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.can_sell_leader_inventory', true);

        $catalog = $this->getJson('/api/v1/partner-sales')
            ->assertOk()
            ->assertJsonPath('data.leader_name', $ana->name)
            ->assertJsonPath('data.products.0.name', 'Té propio')
            ->assertJsonPath('data.products.0.purchase_cost', 0);

        $productId = $catalog->json('data.products.0.id');

        $this->postJson('/api/v1/partner-sales/orders', [
            'customer_name' => 'Cliente de socio',
            'customer_phone' => '70000001',
            'items' => [['product_id' => $productId, 'quantity' => 2]],
            'delivery' => 'pickup',
        ])
            ->assertCreated()
            ->assertJsonPath('data.channel', 'pos')
            ->assertJsonPath('data.partner_user_id', $socio->id)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total', '40.00');

        $this->assertSame(8, $product->fresh()->stock);

        $this->getJson('/api/v1/partner-sales/orders')
            ->assertOk()
            ->assertJsonPath('data.0.customer_name', 'Cliente de socio')
            ->assertJsonPath('sales.orders_count', 1);

        Sanctum::actingAs($ana);
        $this->getJson('/api/v1/my-store/orders?source=team')
            ->assertOk()
            ->assertJsonPath('data.0.partner_user_id', $socio->id);
    }

    public function test_revoke_stops_partner_sales(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);
        $referralId = $this->referralId($ana, $socio);
        $this->putJson('/api/v1/dashboard/team/'.$referralId, ['can_sell_inventory' => true])->assertOk();

        Sanctum::actingAs($ana);
        $this->putJson('/api/v1/dashboard/team/'.$referralId, ['can_sell_inventory' => false])->assertOk();

        Sanctum::actingAs($socio->fresh());
        $this->postJson('/api/v1/partner-sales/orders', [
            'customer_name' => 'Luis',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery' => 'pickup',
        ])->assertForbidden();
    }

    public function test_stranger_cannot_use_partner_sales(): void
    {
        $this->teamWithStock();
        $extraño = User::factory()->create(['email' => 'extraño-seller@inv.test']);
        $extraño->assignRole('partner');
        Sanctum::actingAs($extraño);

        $this->getJson('/api/v1/partner-sales')->assertForbidden();
    }

    public function test_granted_partner_cannot_edit_products(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);
        $referralId = $this->referralId($ana, $socio);
        $this->putJson('/api/v1/dashboard/team/'.$referralId, ['can_sell_inventory' => true])->assertOk();

        Sanctum::actingAs($socio->fresh());
        $this->putJson('/api/v1/products/'.$product->id, [
            'name' => 'Hack',
            'price' => 1,
            'stock' => 99,
        ])->assertForbidden();
    }

    public function test_partner_pos_consumes_warehouse_not_assigned_lot(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $socio->id,
            'quantity' => 4,
        ])->assertCreated();

        $referralId = $this->referralId($ana, $socio);
        $this->putJson('/api/v1/dashboard/team/'.$referralId, ['can_sell_inventory' => true])->assertOk();

        Sanctum::actingAs($socio->fresh());
        $this->postJson('/api/v1/partner-sales/orders', [
            'customer_name' => 'Cliente POS',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'delivery' => 'pickup',
        ])->assertCreated();

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(0, (int) \App\Modules\Store\Models\InventoryAllocation::query()->value('qty_sold'));
        $this->assertSame(4, (int) \App\Modules\Store\Models\InventoryAllocation::query()->value('qty_assigned'));
    }

    public function test_leader_pos_sale_is_still_personal(): void
    {
        [$ana, , $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/my-store/orders', [
            'customer_name' => 'Venta propia',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery' => 'pickup',
        ])
            ->assertCreated()
            ->assertJsonPath('data.partner_user_id', null);

        $this->assertSame(9, $product->fresh()->stock);
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Modules\Store\Models\Product}
     */
    private function teamWithStock(): array
    {
        $ana = $this->leader('ana-seller@inv.test');
        $socio = User::factory()->create([
            'email' => 'socio-seller@inv.test',
            'sponsor_user_id' => $ana->id,
        ]);
        $socio->assignRole('partner');
        Referral::query()->create([
            'referrer_id' => $ana->id,
            'referred_id' => $socio->id,
            'network_id' => $ana->current_network_id,
            'level' => 1,
            'status' => ReferralStatus::Active,
        ]);
        $product = $ana->store->products()->create([
            'name' => 'Té propio',
            'price' => 20,
            'stock' => 10,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        return [$ana, $socio, $product];
    }

    private function referralId(User $leader, User $partner): int
    {
        return (int) Referral::query()
            ->where('referrer_id', $leader->id)
            ->where('referred_id', $partner->id)
            ->value('id');
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
            'slug' => 'net-seller-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-seller-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
