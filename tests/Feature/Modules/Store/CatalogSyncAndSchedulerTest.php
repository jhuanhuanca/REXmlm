<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Jobs\SyncStoreCatalogJob;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\InvitationStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogSyncAndSchedulerTest extends TestCase
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
            'services.catalog.sync_ttl' => 900,
        ]);
    }

    public function test_public_store_does_not_hit_the_company_catalog(): void
    {
        $ana = $this->leader('ana-nosync@inv.test', 7);
        Http::fake();

        $this->getJson('/api/v1/store/'.$ana->store->slug)->assertOk();

        $this->assertNull($ana->store->fresh()->catalog_synced_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/products'));
    }

    public function test_leader_inventory_syncs_catalog_when_stale(): void
    {
        Http::fake([
            'catalog.test/*' => Http::response([
                'data' => [[
                    'id' => 88,
                    'name' => 'HGW Vita',
                    'price' => 20,
                    'is_active' => true,
                ]],
            ], 200),
        ]);

        $ana = $this->leader('ana-stale@inv.test', 7);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/products?source=company')->assertOk();
        $this->assertNotNull($ana->store->fresh()->catalog_synced_at);
        $this->assertTrue(
            $ana->store->products()->where('catalog_product_id', 88)->exists()
        );
    }

    public function test_leader_inventory_skips_catalog_within_ttl(): void
    {
        $ana = $this->leader('ana-fresh@inv.test', 7);
        $ana->store->forceFill(['catalog_synced_at' => now()])->save();
        Sanctum::actingAs($ana);
        Http::fake();

        $this->getJson('/api/v1/products?source=company')->assertOk();

        Http::assertNothingSent();
    }

    public function test_catalog_sync_command_queues_a_job_per_store(): void
    {
        $this->leader('ana-job@inv.test', 7);
        Queue::fake();

        $this->artisan('catalog:sync-stores')
            ->assertSuccessful();

        Queue::assertPushed(SyncStoreCatalogJob::class);
    }

    public function test_schedule_includes_horizon_catalog_and_prune(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('catalog:sync-stores', $output);
        $this->assertStringContainsString('horizon:snapshot', $output);
        $this->assertStringContainsString('sanctum:prune-expired', $output);
        $this->assertStringContainsString('invitations:expire', $output);
    }

    public function test_expire_invitations_marks_pending_past_due(): void
    {
        $ana = $this->leader('ana-inv@inv.test', 7);
        $expired = Invitation::query()->create([
            'leader_id' => $ana->id,
            'network_id' => $ana->current_network_id,
            'email' => 'old@inv.test',
            'token_hash' => hash('sha256', 'x'),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->subDay(),
        ]);
        $alive = Invitation::query()->create([
            'leader_id' => $ana->id,
            'network_id' => $ana->current_network_id,
            'email' => 'new@inv.test',
            'token_hash' => hash('sha256', 'y'),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('invitations:expire')->assertSuccessful();

        $this->assertSame(InvitationStatus::Expired, $expired->fresh()->status);
        $this->assertSame(InvitationStatus::Pending, $alive->fresh()->status);
    }

    public function test_guests_cannot_open_horizon(): void
    {
        $this->get('/horizon')->assertForbidden();
    }

    private function leader(string $email, ?int $companyId = null): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'catalog_company_id' => $companyId,
            'catalog_company_name' => $companyId ? 'HGW' : null,
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-ops-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-ops-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
