<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\MLM\Models\Referral;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\InventoryAllocation;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\ReferralStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryAllocationTest extends TestCase
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

    public function test_leader_assigns_personal_stock_to_a_partner(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $socio->id,
            'quantity' => 4,
            'notes' => 'Entrega de septiembre',
        ])
            ->assertCreated()
            ->assertJsonPath('data.qty_assigned', 4)
            ->assertJsonPath('data.qty_remaining', 4)
            ->assertJsonPath('data.partner_user_id', $socio->id);

        $this->assertSame(6, $product->fresh()->stock);
        $this->assertSame(4, InventoryAllocation::query()->value('qty_assigned'));
    }

    public function test_partner_sale_consumes_assigned_stock_not_warehouse(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $socio->id,
            'quantity' => 5,
        ])->assertCreated();

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'partner_user_id' => $socio->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame(5, $product->fresh()->stock);
        $allocation = InventoryAllocation::query()->first();
        $this->assertSame(2, $allocation->qty_sold);
        $this->assertSame(3, $allocation->remaining());
    }

    public function test_cannot_assign_more_than_warehouse_or_to_a_stranger(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        $stranger = User::factory()->create(['email' => 'otro@inv.test']);
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $socio->id,
            'quantity' => 99,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $stranger->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_return_puts_units_back_in_warehouse(): void
    {
        [$ana, $socio, $product] = $this->teamWithStock();
        Sanctum::actingAs($ana);
        $id = $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $socio->id,
            'quantity' => 3,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/my-store/inventory/allocations/'.$id.'/return', [
            'quantity' => 3,
        ])->assertOk();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, InventoryAllocation::query()->count());
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Modules\Store\Models\Product}
     */
    private function teamWithStock(): array
    {
        $ana = $this->leader('ana-lot@inv.test');
        $socio = User::factory()->create([
            'email' => 'socio-lot@inv.test',
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
            'slug' => 'net-lot-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-lot-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
};
