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

    public function test_overlay_config_is_public(): void
    {
        config(['services.paddle.client_token' => 'live_overlay_test', 'services.paddle.sandbox' => false]);

        $this->getJson('/api/v1/billing/overlay')
            ->assertOk()
            ->assertJsonPath('client_token', 'live_overlay_test')
            ->assertJsonPath('sandbox', false);
    }

    public function test_subscribe_returns_paddle_checkout_url(): void
    {
        Http::fake([
            'sandbox-api.paddle.com/customers*' => Http::response([
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
            'paddle_intro_discount_id' => 'dsc_01test',
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

    public function test_existing_paddle_customer_email_reuses_ctm_id(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, '/customers') && $request->method() === 'GET') {
                return Http::response([
                    'data' => [[
                        'id' => 'ctm_01ktyswqdvyz8g2z1zk22c3yq3',
                        'email' => 'ana-conflict@bill.test',
                    ]],
                ], 200);
            }
            if (str_contains($url, '/customers') && $request->method() === 'POST') {
                return Http::response([
                    'error' => [
                        'code' => 'conflict',
                        'detail' => 'customer email conflicts with customer of id ctm_01ktyswqdvyz8g2z1zk22c3yq3',
                    ],
                ], 409);
            }
            if (str_contains($url, '/transactions')) {
                return Http::response([
                    'data' => [
                        'id' => 'txn_conflict',
                        'checkout' => ['url' => 'https://sandbox-buy.paddle.com/txn_conflict'],
                    ],
                ], 201);
            }

            return Http::response(['data' => []], 200);
        });

        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-conflict',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_conflict',
            'paddle_intro_discount_id' => 'dsc_conflict',
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-conflict@bill.test');
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://sandbox-buy.paddle.com/txn_conflict');

        $this->assertSame('ctm_01ktyswqdvyz8g2z1zk22c3yq3', $ana->fresh()->stripe_id);
    }

    public function test_local_placeholder_subscription_still_opens_paddle_checkout(): void
    {
        Http::fake([
            'sandbox-api.paddle.com/customers*' => Http::response([
                'data' => ['id' => 'ctm_local'],
            ], 201),
            'sandbox-api.paddle.com/transactions' => Http::response([
                'data' => [
                    'id' => 'txn_local',
                    'checkout' => ['url' => 'https://sandbox-buy.paddle.com/txn_local'],
                ],
            ], 201),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-local-pay',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_local',
            'paddle_intro_discount_id' => 'dsc_local',
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-local-pay@bill.test');
        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'local_'.$ana->id,
            'stripe_status' => 'active',
            'stripe_price' => 'local',
            'quantity' => 1,
            'plan_id' => $plan->id,
        ]);

        $this->assertFalse($ana->fresh()->hasPaidPlatformAccess());

        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://sandbox-buy.paddle.com/txn_local');

        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $ana->id,
            'stripe_id' => 'local_'.$ana->id,
        ]);
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

    public function test_zero_dollar_trial_does_not_activate(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-zero',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_zero',
            'paddle_intro_discount_id' => 'dsc_zero',
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-zero@bill.test');

        $payload = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_zero',
                'subscription_id' => 'sub_zero',
                'details' => ['totals' => ['grand_total' => '0']],
                'custom_data' => [
                    'kind' => 'platform_plan',
                    'user_id' => (string) $ana->id,
                    'plan_id' => (string) $plan->id,
                ],
                'items' => [['price' => ['id' => 'pri_zero']]],
            ],
        ];
        $this->callWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $ana->id,
            'stripe_id' => 'sub_zero',
        ]);
    }

    public function test_intro_transaction_does_not_accrue_referral_commission(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_intro',
            'is_active' => true,
        ]);
        $leader = $this->leader('ref-intro@bill.test');
        $ana = $this->leader('ana-intro@bill.test');
        $ana->forceFill(['sponsor_user_id' => $leader->id])->save();

        $payload = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_intro',
                'subscription_id' => 'sub_intro',
                'details' => ['totals' => ['grand_total' => '100']],
                'custom_data' => [
                    'kind' => 'platform_plan',
                    'user_id' => (string) $ana->id,
                    'plan_id' => (string) $plan->id,
                ],
                'items' => [['price' => ['id' => 'pri_intro']]],
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->callWebhook($raw)->assertOk();

        $this->assertDatabaseMissing('commissions', ['referred_id' => $ana->id]);
    }

    public function test_list_price_transaction_accrues_commission_once(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-full',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_full',
            'is_active' => true,
        ]);
        $leader = $this->leader('ref-full@bill.test');
        $ana = $this->leader('ana-full@bill.test');
        $ana->forceFill(['sponsor_user_id' => $leader->id])->save();

        $payload = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_full',
                'subscription_id' => 'sub_full',
                'details' => ['totals' => ['grand_total' => '4900']],
                'custom_data' => [
                    'kind' => 'platform_plan',
                    'user_id' => (string) $ana->id,
                    'plan_id' => (string) $plan->id,
                ],
                'items' => [['price' => ['id' => 'pri_full']]],
            ],
        ];
        $this->callWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();
        $this->callWebhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

        $this->assertDatabaseCount('commissions', 1);
        $this->assertDatabaseHas('commissions', [
            'referrer_id' => $leader->id,
            'referred_id' => $ana->id,
            'amount' => 4.9,
        ]);
    }

    public function test_later_renewals_or_new_subscriptions_do_not_accrue_again(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-once',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_once',
            'is_active' => true,
        ]);
        $leader = $this->leader('ref-once@bill.test');
        $ana = $this->leader('ana-once@bill.test');
        $ana->forceFill(['sponsor_user_id' => $leader->id])->save();

        $first = [
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_once_1',
                'subscription_id' => 'sub_once_1',
                'details' => ['totals' => ['grand_total' => '4900']],
                'custom_data' => [
                    'kind' => 'platform_plan',
                    'user_id' => (string) $ana->id,
                    'plan_id' => (string) $plan->id,
                ],
                'items' => [['price' => ['id' => 'pri_once']]],
            ],
        ];
        $renewal = $first;
        $renewal['data']['id'] = 'txn_once_2';
        $resubscribe = $first;
        $resubscribe['data']['id'] = 'txn_once_3';
        $resubscribe['data']['subscription_id'] = 'sub_once_2';

        $this->callWebhook(json_encode($first, JSON_THROW_ON_ERROR))->assertOk();
        $this->callWebhook(json_encode($renewal, JSON_THROW_ON_ERROR))->assertOk();
        $this->callWebhook(json_encode($resubscribe, JSON_THROW_ON_ERROR))->assertOk();

        $this->assertDatabaseCount('commissions', 1);
        $this->assertDatabaseHas('commissions', [
            'referred_id' => $ana->id,
            'amount' => 4.9,
        ]);
    }

    public function test_past_due_locks_leader_modules(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-due',
            'price' => 49,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_due',
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-due@bill.test');
        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_due',
            'stripe_status' => 'past_due',
            'stripe_price' => 'pri_due',
            'quantity' => 1,
            'plan_id' => $plan->id,
        ]);

        Sanctum::actingAs($ana);
        $this->putJson('/api/v1/my-store', ['name' => 'No'])
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_past_due');
    }

    public function test_basico_cannot_manage_store(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Básico',
            'slug' => 'basico',
            'price' => 29,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_basico',
            'is_active' => true,
            'features' => ['store' => false, 'tools' => false, 'landing' => true, 'team' => true],
        ]);
        $ana = $this->leader('ana-basico@bill.test');
        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_basico',
            'stripe_status' => 'active',
            'stripe_price' => 'pri_basico',
            'quantity' => 1,
            'plan_id' => $plan->id,
        ]);

        Sanctum::actingAs($ana);
        $this->putJson('/api/v1/my-store', ['name' => 'No tienda'])
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_feature_required');
    }

    public function test_renewal_reminder_two_days_ahead(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-mail',
            'price' => 49,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-mail@bill.test');
        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_mail',
            'stripe_status' => 'active',
            'stripe_price' => 'pri_mail',
            'quantity' => 1,
            'plan_id' => $plan->id,
            'next_billed_at' => now()->addDays(2),
        ]);

        $this->artisan('rexmlm:notify-subscription-lifecycle')->assertOk();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\SubscriptionRenewalReminderMail::class, 1);

        $this->artisan('rexmlm:notify-subscription-lifecycle')->assertOk();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\SubscriptionRenewalReminderMail::class, 1);
    }

    public function test_checkout_sends_intro_discount_id(): void
    {
        Http::fake([
            'sandbox-api.paddle.com/customers*' => Http::response(['data' => ['id' => 'ctm_dsc']], 201),
            'sandbox-api.paddle.com/transactions' => Http::response([
                'data' => [
                    'id' => 'txn_dsc',
                    'checkout' => ['url' => 'https://sandbox-buy.paddle.com/txn_dsc'],
                ],
            ], 201),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-dsc',
            'price' => 49,
            'intro_price' => 1,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_dsc',
            'paddle_intro_discount_id' => 'dsc_01intro',
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-dsc@bill.test');
        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/transactions')
                && ($request->data()['discount_id'] ?? null) === 'dsc_01intro';
        });
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

    public function test_cancel_schedules_end_of_period(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-cancel',
            'price' => 49,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-cancel@bill.test');
        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_cancel',
            'stripe_status' => 'active',
            'stripe_price' => 'pri_cancel',
            'quantity' => 1,
            'plan_id' => $plan->id,
            'next_billed_at' => now()->addDays(12),
        ]);

        Http::fake([
            'sandbox-api.paddle.com/subscriptions/sub_cancel/cancel' => Http::response(['data' => ['id' => 'sub_cancel', 'status' => 'active']], 200),
        ]);

        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/subscriptions/cancel')->assertOk();

        $this->assertNotNull($ana->fresh()->subscription('default')?->ends_at);
    }

    public function test_invoices_come_from_paddle(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Intermedio',
            'slug' => 'intermedio-inv',
            'price' => 49,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'is_active' => true,
        ]);
        $ana = $this->leader('ana-inv@bill.test');
        $ana->forceFill(['stripe_id' => 'ctm_inv'])->save();
        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_inv',
            'stripe_status' => 'active',
            'stripe_price' => 'pri_inv',
            'quantity' => 1,
            'plan_id' => $plan->id,
        ]);

        Http::fake([
            'sandbox-api.paddle.com/transactions*' => Http::response([
                'data' => [[
                    'id' => 'txn_inv_1',
                    'status' => 'completed',
                    'billed_at' => '2026-09-01T00:00:00Z',
                    'currency_code' => 'USD',
                    'details' => ['totals' => ['grand_total' => '4900']],
                    'items' => [['price' => ['description' => 'Intermedio']]],
                ]],
            ], 200),
        ]);

        Sanctum::actingAs($ana);
        $this->getJson('/api/v1/subscriptions/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'txn_inv_1')
            ->assertJsonPath('data.0.amount', 49);
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
