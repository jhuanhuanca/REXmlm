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

class DropshippingShippingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_quote_uses_local_department_national_and_international_fees(): void
    {
        $store = $this->storeWithDropshipping();

        $this->assertQuote($store, ['country' => 'BO', 'department' => 'La Paz', 'area' => 'El Alto'], 7, 'Área local');
        $this->assertQuote($store, ['country' => 'BO', 'department' => 'La Paz', 'area' => 'Zona Sur'], 12, 'Mismo departamento');
        $this->assertQuote($store, ['country' => 'BO', 'department' => 'Santa Cruz', 'area' => 'Plan 3000'], 22, 'Nacional (otro departamento)');
        $this->assertQuote($store, ['country' => 'PE', 'department' => 'Lima', 'area' => 'Miraflores'], 52, 'Internacional');
    }

    public function test_specific_zone_beats_default_fees(): void
    {
        $store = $this->storeWithDropshipping([
            'zones' => [[
                'country' => 'BO',
                'department' => 'Santa Cruz',
                'area' => 'Plan 3000',
                'fee' => 15,
                'eta_days' => 3,
                'label' => 'Santa Cruz urbano',
            ]],
        ]);

        $this->assertQuote($store, ['country' => 'BO', 'department' => 'Santa Cruz', 'area' => 'Plan 3000'], 17, 'Santa Cruz urbano');
        $this->assertQuote($store, ['country' => 'BO', 'department' => 'Santa Cruz', 'area' => 'Montero'], 22, 'Nacional (otro departamento)');
    }

    public function test_free_shipping_threshold_clears_fee_and_handling(): void
    {
        $store = $this->storeWithDropshipping(['free_shipping_from' => 100]);

        $this->getJson('/api/v1/store/'.$store->slug.'/shipping-quote?'.http_build_query([
            'country' => 'BO',
            'department' => 'La Paz',
            'area' => 'El Alto',
            'subtotal' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('data.free', true)
            ->assertJsonPath('data.fee', 0)
            ->assertJsonPath('data.handling_fee', 0)
            ->assertJsonPath('data.shipping_fee', 0);
    }

    public function test_order_adds_shipping_when_dropshipping_is_enabled(): void
    {
        $ana = $this->leader('ana-ship@inv.test');
        $this->enableDropshipping($ana->store);
        $product = $ana->store->products()->create([
            'name' => 'Caja drop',
            'price' => 40,
            'stock' => 0,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Dropship,
        ]);

        $response = $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'customer_phone' => '70000000',
            'shipping_country' => 'bo',
            'shipping_department' => 'La Paz',
            'shipping_area' => 'El Alto',
            'shipping_address' => 'Av. 6 de Marzo',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertEquals(87, (float) $response->json('data.total'));
        $this->assertEquals(7, (float) $response->json('data.shipping_fee'));
        $this->assertSame('BO', $response->json('data.shipping_country'));
        $this->assertSame('Área local', $response->json('data.shipping_zone'));
        $this->assertSame(1, $response->json('data.shipping_eta_days'));
    }

    public function test_enabled_dropshipping_requires_country(): void
    {
        $ana = $this->leader('ana-need-country@inv.test');
        $this->enableDropshipping($ana->store);
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 10,
            'stock' => 4,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipping_country']);
    }

    public function test_disabled_dropshipping_keeps_product_total_without_country(): void
    {
        $ana = $this->leader('ana-off-ship@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 10,
            'stock' => 4,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $response = $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertEquals(10, (float) $response->json('data.total'));
        $this->assertEquals(0, (float) $response->json('data.shipping_fee'));
        $this->assertNull($response->json('data.shipping_country'));
    }

    public function test_saving_dropshipping_replaces_zones_instead_of_merging_indexes(): void
    {
        $ana = $this->leader('ana-zones@inv.test');
        $ana->store->update([
            'settings' => [
                'whatsapp' => '70011111',
                'dropshipping' => [
                    'enabled' => true,
                    'origin_country' => 'BO',
                    'zones' => [
                        ['country' => 'PE', 'department' => 'Lima', 'area' => '', 'fee' => 40, 'eta_days' => 8, 'label' => 'Lima'],
                        ['country' => 'CL', 'department' => '', 'area' => '', 'fee' => 60, 'eta_days' => 12, 'label' => 'Chile'],
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($ana);
        $this->putJson('/api/v1/my-store', [
            'settings' => [
                'dropshipping' => [
                    'enabled' => true,
                    'origin_country' => 'BO',
                    'local_fee' => 5,
                    'zones' => [[
                        'country' => 'ar',
                        'department' => 'Buenos Aires',
                        'area' => '',
                        'fee' => 33,
                        'eta_days' => 6,
                        'label' => 'Argentina',
                    ]],
                ],
            ],
        ])->assertOk();

        $dropshipping = $ana->store->fresh()->settings['dropshipping'];
        $this->assertTrue($dropshipping['enabled']);
        $this->assertSame('70011111', $ana->store->fresh()->settings['whatsapp']);
        $this->assertCount(1, $dropshipping['zones']);
        $this->assertSame('AR', $dropshipping['zones'][0]['country']);
        $this->assertEquals(33, $dropshipping['zones'][0]['fee']);
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function assertQuote(Store $store, array $destination, float $fee, string $zone): void
    {
        $response = $this->getJson('/api/v1/store/'.$store->slug.'/shipping-quote?'.http_build_query([
            ...$destination,
            'subtotal' => 40,
        ]))->assertOk();

        $this->assertTrue((bool) $response->json('data.applies'));
        $this->assertFalse((bool) $response->json('data.free'));
        $this->assertEquals($fee, (float) $response->json('data.fee'));
        $this->assertSame($zone, $response->json('data.zone'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storeWithDropshipping(array $overrides = []): Store
    {
        $ana = $this->leader('ana-quote-'.uniqid().'@inv.test');
        $this->enableDropshipping($ana->store, $overrides);

        return $ana->store->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enableDropshipping(Store $store, array $overrides = []): void
    {
        $store->update([
            'settings' => [
                'dropshipping' => array_replace([
                    'enabled' => true,
                    'origin_country' => 'BO',
                    'origin_department' => 'La Paz',
                    'origin_area' => 'El Alto',
                    'handling_fee' => 2,
                    'free_shipping_from' => null,
                    'local_fee' => 5,
                    'department_fee' => 10,
                    'national_fee' => 20,
                    'international_fee' => 50,
                    'local_days' => 1,
                    'department_days' => 2,
                    'national_days' => 4,
                    'international_days' => 10,
                    'zones' => [],
                ], $overrides),
            ],
        ]);
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
            'slug' => 'net-ship-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-ship-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
