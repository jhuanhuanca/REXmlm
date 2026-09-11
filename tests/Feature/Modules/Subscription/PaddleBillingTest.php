<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Subscription;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Store;
use App\Modules\Subscription\Models\Plan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaddleBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'cashier.secret' => null,
            'billing.offline' => false,
            'services.paddle.api_key' => 'pdl_test_key',
            'services.paddle.webhook_secret' => 'pdl_ntfset_test',
            'services.paddle.sandbox' => true,
        ]);
    }

    public function test_without_paddle_and_without_offline_a_leader_cannot_use_paid_routes(): void
    {
        config(['services.paddle.api_key' => '']);
        $ana = $this->leader('ana-block@bill.test');
        Sanctum::actingAs($ana);

        $this->putJson('/api/v1/my-store', [
            'name' => 'Tienda bloqueada',
        ])->assertForbidden();
    }

    public function test_subscribe_returns_paddle_checkout_url(): void
    {
        Http::fake([
            'sandbox-api.paddle.com/customers' => Http::response([
                'data' => ['id' => 'ctm_01test'],
            ], 201),
            'sandbox-api.paddle.com/transactions' => Http::response([
                'data' => [
                    'id' => 'txn_01test',
                    'checkout' => ['url' => 'https://sandbox-buy.paddle.com/txn_01test'],
                ],
            ], 201),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Líder',
            'slug' => 'lider-test',
            'price' => 29,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_01test',
            'is_active' => true,
        ]);

        $ana = $this->leader('ana-pay@bill.test');
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/subscriptions', [
            'plan_id' => $plan->id,
        ])->assertOk()
            ->assertJsonPath('checkout_url', 'https://sandbox-buy.paddle.com/txn_01test')
            ->assertJsonPath('offline', false);
    }

    public function test_paddle_webhook_activates_subscription(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Líder',
            'slug' => 'lider-hook',
            'price' => 29,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_01hook',
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-hook@bill.test');

        $payload = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_01hook',
                'subscription_id' => 'sub_01hook',
                'custom_data' => [
                    'kind' => 'platform_plan',
                    'user_id' => (string) $ana->id,
                    'plan_id' => (string) $plan->id,
                ],
                'items' => [
                    ['price' => ['id' => 'pri_01hook']],
                ],
            ],
        ];

        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->callWebhook($raw)->assertOk()->assertJsonPath('received', true);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $ana->id,
            'stripe_id' => 'sub_01hook',
            'stripe_status' => 'active',
            'plan_id' => $plan->id,
        ]);

        Sanctum::actingAs($ana->fresh());
        $this->putJson('/api/v1/my-store', [
            'name' => 'Tienda cobrada',
        ])->assertOk();
    }

    public function test_invalid_paddle_signature_is_rejected(): void
    {
        $this->withHeader('Paddle-Signature', 'ts='.time().';h1=deadbeef')
            ->postJson('/api/v1/billing/webhook', [
                'event_type' => 'transaction.completed',
                'data' => [],
            ])
            ->assertForbidden();
    }

    public function test_public_store_order_does_not_use_paddle(): void
    {
        $ana = $this->leader('ana-shop@bill.test');
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
            'payment_method' => 'transfer',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.payment_method', 'transfer');
    }

    private function callWebhook(string $raw)
    {
        $ts = (string) time();
        $h1 = hash_hmac('sha256', $ts.':'.$raw, (string) config('services.paddle.webhook_secret'));

        return $this->call(
            'POST',
            '/api/v1/billing/webhook',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PADDLE_SIGNATURE' => 'ts='.$ts.';h1='.$h1,
                'CONTENT_TYPE' => 'application/json',
            ],
            $raw,
        );
    }

    private function leader(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'country' => 'BO']);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-bill-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-bill-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store', 'ownedNetwork']);
    }
}
