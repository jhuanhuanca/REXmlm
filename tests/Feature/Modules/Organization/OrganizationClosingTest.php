<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Organization;

use App\Models\User;
use App\Modules\Organization\Actions\SyncUserOrganization;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Services\ClosingTimezone;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\NetworkStatus;
use App\Shared\Enums\OrderStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_timezone_follows_leader_country_and_defaults_to_la_paz(): void
    {
        $timezone = app(ClosingTimezone::class);

        $bolivia = User::factory()->create(['country' => 'BO']);
        $venezuela = User::factory()->create(['country' => 'VE']);
        $unknown = User::factory()->create(['country' => null]);

        $this->assertSame('America/La_Paz', $timezone->forUser($bolivia));
        $this->assertSame('America/Caracas', $timezone->forUser($venezuela));
        $this->assertSame('America/La_Paz', $timezone->forUser($unknown));
    }

    public function test_leader_can_belong_to_a_second_organization_without_losing_primary(): void
    {
        $sync = app(SyncUserOrganization::class);
        $user = User::factory()->create([
            'catalog_company_id' => 1,
            'catalog_company_name' => 'HGW',
        ]);

        $first = $sync->handle($user, 1, 'HGW');
        $this->assertNotNull($first);
        $this->assertSame('hgw', $first->slug);

        $other = Organization::query()->create([
            'name' => 'Otra',
            'slug' => 'otra',
            'default_timezone' => 'America/La_Paz',
            'default_currency' => 'USD',
            'status' => 'active',
        ]);

        $sync->attach($user->fresh(), $other);

        $user->refresh();
        $this->assertSame($first->id, $user->organization_id);
        $this->assertDatabaseHas('organization_users', [
            'user_id' => $user->id,
            'organization_id' => $other->id,
        ]);
        $this->assertDatabaseHas('organization_users', [
            'user_id' => $user->id,
            'organization_id' => $first->id,
        ]);
    }

    public function test_closing_period_uses_leader_timezone_not_utc(): void
    {
        $leader = User::factory()->create(['country' => 'BO']);
        $network = Network::query()->create([
            'owner_user_id' => $leader->id,
            'name' => $leader->name,
            'slug' => 'net-'.$leader->id,
            'status' => NetworkStatus::Active,
        ]);
        $leader->forceFill(['current_network_id' => $network->id])->save();

        $store = Store::query()->create([
            'user_id' => $leader->id,
            'network_id' => $network->id,
            'name' => 'Tienda',
            'slug' => 'tienda-'.$leader->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        Order::query()->create([
            'store_id' => $store->id,
            'network_id' => $network->id,
            'customer_name' => 'Cliente',
            'customer_email' => 'cliente@example.com',
            'status' => OrderStatus::Paid,
            'total' => 50,
            'currency' => 'USD',
            'paid_at' => Carbon::parse('2026-09-01 02:00:00', 'UTC'),
        ]);

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');

        $this->assertSame('America/La_Paz', $report['timezone']);
        $this->assertSame('own_network', $report['scope']);
        $this->assertSame(50.0, $report['sales']);
    }
}
