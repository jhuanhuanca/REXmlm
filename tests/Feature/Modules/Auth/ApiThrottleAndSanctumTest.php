<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Models\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiThrottleAndSanctumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_public_store_is_rate_limited(): void
    {
        $ana = $this->leader('ana-throttle@inv.test');

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(1)->by('test-public');
        });

        $this->getJson('/api/v1/store/'.$ana->store->slug)->assertOk();
        $this->getJson('/api/v1/store/'.$ana->store->slug)->assertStatus(429);
    }

    public function test_billing_webhook_is_not_rate_limited_by_api_limiter(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/v1/billing/webhook')) {
                return Limit::none();
            }

            return Limit::perMinute(1)->by('test-api');
        });

        $this->postJson('/api/v1/billing/webhook', ['event_type' => 'x'])
            ->assertForbidden();
        $this->postJson('/api/v1/billing/webhook', ['event_type' => 'x'])
            ->assertForbidden();
    }

    public function test_auth_token_expires(): void
    {
        config(['sanctum.stateful' => []]);

        $user = User::factory()->create(['email' => 'token-exp@inv.test']);
        $plain = $user->createAuthToken();

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();

        $user->tokens()->update(['expires_at' => now()->subMinute()]);
        $this->flushSession();
        Auth::forgetGuards();

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_new_auth_token_stores_expires_at(): void
    {
        $user = User::factory()->create(['email' => 'token-ttl@inv.test']);
        $user->createAuthToken();

        $token = $user->tokens()->first();
        $this->assertNotNull($token?->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(13)));
        $this->assertTrue($token->expires_at->lessThan(now()->addDays(15)));
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
            'slug' => 'net-thr-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-thr-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
