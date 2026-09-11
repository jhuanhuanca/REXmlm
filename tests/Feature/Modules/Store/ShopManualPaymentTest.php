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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_leader_can_save_payment_settings(): void
    {
        $ana = $this->leader('ana-pay@inv.test');
        Sanctum::actingAs($ana);

        $this->putJson('/api/v1/my-store', [
            'settings' => [
                'payments' => [
                    'qr_enabled' => true,
                    'qr_image' => 'https://cdn.test/qr.png',
                    'qr_notes' => 'Paga el monto exacto',
                    'binance_enabled' => true,
                    'binance_pay_id' => '123456789',
                    'deposit_enabled' => true,
                    'transfer_enabled' => true,
                    'bank_name' => 'Banco Unión',
                    'bank_account_holder' => 'Ana Pérez',
                    'bank_account_number' => '100020003000',
                    'bank_account_type' => 'ahorros',
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/store/'.$ana->store->slug)
            ->assertOk()
            ->assertJsonPath('data.payments.qr_enabled', true)
            ->assertJsonPath('data.payments.bank_account_number', '100020003000')
            ->assertJsonPath('data.payments.binance_pay_id', '123456789');
    }

    public function test_public_order_stores_payment_method(): void
    {
        $ana = $this->leader('ana-order-pay@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 20,
            'stock' => 4,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'payment_method' => 'qr_binance',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'qr_binance')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $ana = $this->leader('ana-bad-pay@inv.test');
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 10,
            'stock' => 2,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $this->postJson('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_checkout_voucher_is_stored_and_registered_in_catalog(): void
    {
        Storage::fake('local');
        config([
            'app.url' => 'http://rexmlm.test',
            'services.catalog.url' => 'http://catalog.test/api/v1',
            'services.catalog.token' => 'test-token',
            'services.catalog.cache_ttl' => 0,
        ]);
        Http::fake([
            'http://catalog.test/api/v1/companies/7/documents' => Http::response([
                'data' => ['id' => 88, 'file_type' => 'voucher'],
            ], 201),
        ]);

        $ana = $this->leader('ana-voucher@inv.test');
        $ana->forceFill(['catalog_company_id' => 7])->save();
        $product = $ana->store->products()->create([
            'name' => 'Caja',
            'price' => 20,
            'stock' => 4,
            'is_active' => true,
            'is_published' => true,
            'source' => ProductSource::Personal,
            'fulfillment' => ProductFulfillment::Stock,
        ]);

        $response = $this->post('/api/v1/store/'.$ana->store->slug.'/orders', [
            'customer_name' => 'Luis',
            'customer_email' => 'luis@buy.test',
            'payment_method' => 'transfer',
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'voucher' => UploadedFile::fake()->image('comprobante.jpg', 180, 180),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.payment_method', 'transfer')
            ->assertJsonPath('data.has_payment_voucher', true)
            ->assertJsonPath('data.payment_voucher_url', null)
            ->assertJsonMissingPath('data.payment_voucher_document_id');

        $this->assertNotEmpty(Storage::disk('local')->files('vouchers/'.$ana->store->id));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/companies/7/documents')
            && $request['file_type'] === 'voucher'
            && empty($request['url']));

        Sanctum::actingAs($ana);
        $this->get('/api/v1/my-store/orders/'.$response->json('data.id').'/voucher')
            ->assertOk();
    }

    public function test_leader_can_upload_payment_qr(): void
    {
        Storage::fake('public');
        $ana = $this->leader('ana-qr-upload@inv.test');
        Sanctum::actingAs($ana);

        $this->post('/api/v1/my-store/payment-assets', [
            'kind' => 'qr',
            'file' => UploadedFile::fake()->image('qr.png', 120, 120),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.payments.qr_enabled', true);

        $this->assertNotEmpty($ana->store->fresh()->settings['payments']['qr_image'] ?? null);
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
            'slug' => 'net-pay-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-pay-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
