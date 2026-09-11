<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Subscription\Models\Plan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoLiveCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_as_production_fails_on_debug_offline_and_weak_token(): void
    {
        config([
            'app.debug' => true,
            'app.url' => 'http://rexmlm.test',
            'billing.offline' => true,
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'services.catalog.token' => 'short',
            'cors.allowed_origins' => ['http://localhost:5173'],
            'sanctum.stateful' => ['localhost'],
            'services.paddle.api_key' => '',
            'cashier.secret' => null,
        ]);

        $this->artisan('rexmlm:go-live-check', ['--as-production' => true])
            ->assertFailed();
    }

    public function test_as_production_passes_when_hardened(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.url' => 'https://xeft.rexmlm.tech',
            'billing.offline' => false,
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'services.catalog.token' => str_repeat('a', 40),
            'cors.allowed_origins' => ['https://rexmlm.tech', 'https://adxm.rexmlm.tech'],
            'sanctum.stateful' => [],
            'services.paddle.api_key' => 'pdl_live_test',
            'services.paddle.webhook_secret' => 'pdl_ntfset_test',
            'mail.default' => 'smtp',
            'session.driver' => 'cookie',
        ]);

        Plan::query()->create([
            'name' => 'Pro',
            'price' => 10,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_test',
            'is_active' => true,
        ]);

        $this->artisan('rexmlm:go-live-check', ['--as-production' => true])
            ->assertOk();
    }

    public function test_health_stays_open_when_not_production(): void
    {
        $this->get('/up')->assertOk();
    }
}
