<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\MLM\Models\Referral;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Shared\Enums\ReferralStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResourcePolicyIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null, 'billing.offline' => true]);
    }

    public function test_leader_cannot_mutate_another_leaders_catalog_order_or_stock(): void
    {
        $ana = $this->leader('ana-idor@inv.test');
        $mei = $this->leader('mei-idor@inv.test');

        $product = $ana->store->products()->create([
            'name' => 'Caja Ana',
            'price' => 12,
            'stock' => 20,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $category = $ana->store->productCategories()->create(['name' => 'Cat Ana', 'sort' => 1]);

        $orderId = $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis-idor@buy.test',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($ana);
        $allocationId = $this->postJson('/api/v1/my-store/inventory/allocations', [
            'product_id' => $product->id,
            'partner_user_id' => $this->partnerOf($ana)->id,
            'quantity' => 2,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($mei);

        $this->putJson('/api/v1/products/'.$product->id, ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson('/api/v1/products/'.$product->id)->assertNotFound();
        $this->putJson('/api/v1/my-store/categories/'.$category->id, ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson('/api/v1/my-store/categories/'.$category->id)->assertNotFound();
        $this->postJson('/api/v1/my-store/orders/'.$orderId.'/pay')->assertNotFound();
        $this->get('/api/v1/my-store/orders/'.$orderId.'/voucher')->assertNotFound();
        $this->postJson('/api/v1/my-store/inventory/allocations/'.$allocationId.'/return', [
            'quantity' => 1,
        ])->assertNotFound();

        $this->assertSame('Caja Ana', $product->fresh()->name);
        $this->assertSame('Cat Ana', $category->fresh()->name);
    }

    public function test_leader_cannot_read_another_leaders_referral(): void
    {
        $ana = $this->leader('ana-ref-idor@inv.test');
        $mei = $this->leader('mei-ref-idor@inv.test');
        $socio = $this->partnerOf($ana);
        $referral = Referral::query()
            ->where('referrer_id', $ana->id)
            ->where('referred_id', $socio->id)
            ->firstOrFail();

        Sanctum::actingAs($mei);
        $this->getJson('/api/v1/dashboard/team/'.$referral->id)->assertNotFound();
        $this->putJson('/api/v1/dashboard/team/'.$referral->id, [
            'notes' => 'no',
        ])->assertNotFound();
    }

    private function leader(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'country' => 'BO']);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-idor-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        $user->store()->create([
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-idor-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }

    private function partnerOf(User $leader): User
    {
        $socio = User::factory()->create(['email' => 'socio-'.$leader->id.'@inv.test']);
        $socio->assignRole('partner');
        Referral::query()->firstOrCreate(
            [
                'referrer_id' => $leader->id,
                'referred_id' => $socio->id,
            ],
            [
                'network_id' => $leader->current_network_id,
                'level' => 1,
                'status' => ReferralStatus::Active,
            ],
        );

        return $socio;
    }
}
