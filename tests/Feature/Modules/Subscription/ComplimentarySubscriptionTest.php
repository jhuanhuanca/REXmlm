<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Subscription;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Models\Store;
use App\Modules\Subscription\Models\Plan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplimentarySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'billing.offline' => false,
            'services.paddle.api_key' => 'pdl_test_key',
        ]);
    }

    public function test_complimentary_grant_unlocks_leader_without_paddle(): void
    {
        $ana = $this->leader('ana-comp@bill.test');
        $this->plan();

        Sanctum::actingAs($ana);
        $this->putJson('/api/v1/my-store', ['name' => 'Aún bloqueada'])->assertForbidden();

        $this->artisan('rexmlm:grant-complimentary', ['email' => $ana->email])
            ->assertOk();

        $ana->refresh();
        $this->assertTrue($ana->subscribed('default'));
        $this->assertTrue(str_starts_with((string) $ana->subscription('default')?->stripe_id, 'comp_'));

        Sanctum::actingAs($ana);
        $this->putJson('/api/v1/my-store', ['name' => 'Tienda cortesía'])->assertOk();
    }

    public function test_complimentary_revoke_locks_again(): void
    {
        $ana = $this->leader('ana-revoke@bill.test');
        $this->plan();

        $this->artisan('rexmlm:grant-complimentary', ['email' => $ana->email])->assertOk();
        $this->artisan('rexmlm:grant-complimentary', [
            'email' => $ana->email,
            '--revoke' => true,
        ])->assertOk();

        Sanctum::actingAs($ana->fresh());
        $this->putJson('/api/v1/my-store', ['name' => 'Otra vez bloqueada'])->assertForbidden();
    }

    public function test_does_not_overwrite_a_paddle_subscription(): void
    {
        $ana = $this->leader('ana-paddle-keep@bill.test');
        $plan = $this->plan();

        $ana->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_live_keep',
            'stripe_status' => 'active',
            'stripe_price' => 'pri_keep',
            'quantity' => 1,
            'plan_id' => $plan->id,
        ]);

        $this->artisan('rexmlm:grant-complimentary', ['email' => $ana->email])
            ->assertFailed();

        $this->assertSame('sub_live_keep', $ana->fresh()->subscription('default')?->stripe_id);
    }

    private function plan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Pro cortesía',
            'price' => 10,
            'currency' => 'USD',
            'interval' => 'month',
            'commission_percentage' => 10,
            'paddle_price_id' => 'pri_comp_test',
            'is_active' => true,
        ]);
    }

    private function leader(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'country' => 'BO']);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-comp-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-comp-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store', 'ownedNetwork']);
    }
}
