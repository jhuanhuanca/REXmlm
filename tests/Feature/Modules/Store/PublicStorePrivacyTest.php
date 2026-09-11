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

class PublicStorePrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_public_store_hides_costs_dropship_urls_and_warehouse_origin(): void
    {
        $ana = $this->leader('ana-public@inv.test');
        $ana->store->update([
            'settings' => [
                'dropshipping' => [
                    'enabled' => true,
                    'origin_country' => 'BO',
                    'origin_department' => 'La Paz',
                    'origin_area' => 'Depósito secreto',
                    'national_fee' => 20,
                    'notes' => 'Envíos de lunes a viernes',
                ],
                'inventory' => [
                    'target_margin_percent' => 45,
                ],
                'payments' => [
                    'transfer_enabled' => true,
                    'bank_account_number' => '100020003000',
                ],
            ],
        ]);

        $product = $ana->store->products()->create([
            'name' => 'Serum',
            'slug' => 'serum-publico',
            'price' => 80,
            'purchase_cost' => 22.5,
            'stock' => 3,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Dropship,
            'dropship_url' => 'https://proveedor.example/serum',
            'dropship_sku' => 'SUP-99',
        ]);

        $public = $this->getJson('/api/v1/store/'.$ana->store->slug)->assertOk();
        $public->assertJsonPath('data.payments.bank_account_number', '100020003000');
        $public->assertJsonPath('data.dropshipping.enabled', true);
        $public->assertJsonPath('data.dropshipping.notes', 'Envíos de lunes a viernes');
        $public->assertJsonMissingPath('data.settings');
        $public->assertJsonMissingPath('data.inventory');
        $public->assertJsonMissingPath('data.dropshipping.origin_area');
        $public->assertJsonMissingPath('data.products.0.purchase_cost');
        $public->assertJsonMissingPath('data.products.0.unit_cost');
        $public->assertJsonMissingPath('data.products.0.unit_profit');
        $public->assertJsonMissingPath('data.products.0.margin_percent');
        $public->assertJsonMissingPath('data.products.0.dropship_url');
        $public->assertJsonMissingPath('data.products.0.dropship_sku');

        $this->getJson('/api/v1/store/'.$ana->store->slug.'/products/'.$product->slug)
            ->assertOk()
            ->assertJsonMissingPath('data.purchase_cost')
            ->assertJsonMissingPath('data.dropship_url');

        Sanctum::actingAs($ana);
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.purchase_cost', '22.50')
            ->assertJsonPath('data.0.dropship_url', 'https://proveedor.example/serum');
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
            'slug' => 'net-pub-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-pub-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
