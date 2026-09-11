<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Report;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\MLM\Services\DashboardMetricsService;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationVolume;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\OrderStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPhase5Test extends TestCase
{
    use RefreshDatabase;

    public function test_series_keeps_missing_personal_volume_as_null_not_zero(): void
    {
        [$organization, $leader] = $this->orgWithLeader('hgw-p5', 'HGW', 'serie@p5.test');
        $this->memberWithVolume($organization, $leader, 'L5', 200, 800);

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $series = $report['series'];

        $this->assertCount(6, $series);
        $this->assertSame('2026-03', $series[0]['month']);
        $this->assertSame('2026-08', $series[5]['month']);
        $this->assertNull($series[0]['personal']);
        $this->assertNull($series[0]['group']);
        $this->assertNotSame(0, $series[0]['personal']);
        $this->assertSame(200.0, $series[5]['personal']);
        $this->assertSame(800.0, $series[5]['group']);
    }

    public function test_dashboard_exposes_closing_without_faking_company_volume(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 12:00:00', 'America/La_Paz'));

        [, $leader] = $this->orgWithLeader('dxn-p5', 'DXN', 'dash@p5.test');
        $summary = app(DashboardMetricsService::class)->summary($leader->fresh());

        $this->assertFalse($summary['closing']['company_volume']['available']);
        $this->assertNull($summary['closing']['company_volume']['personal']);
        $this->assertNull($summary['closing']['company_volume']['group']);
        $this->assertCount(6, $summary['series']);

        foreach ($summary['series'] as $point) {
            $this->assertNull($point['personal']);
            $this->assertNull($point['group']);
        }
    }

    public function test_dashboard_store_sales_are_the_current_month_not_all_time(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 12:00:00', 'America/La_Paz'));

        [, $leader] = $this->orgWithLeader('omni-p5', 'Omnilife', 'sales@p5.test');
        $store = Store::query()->create([
            'user_id' => $leader->id,
            'network_id' => $leader->current_network_id,
            'name' => 'Tienda',
            'slug' => 'tienda-p5-'.$leader->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        Order::query()->create([
            'store_id' => $store->id,
            'network_id' => $leader->current_network_id,
            'customer_name' => 'Julio',
            'customer_email' => 'julio@p5.test',
            'status' => OrderStatus::Paid,
            'total' => 100,
            'currency' => 'USD',
            'paid_at' => Carbon::parse('2026-07-20 12:00:00', 'America/La_Paz')->utc(),
        ]);
        Order::query()->create([
            'store_id' => $store->id,
            'network_id' => $leader->current_network_id,
            'customer_name' => 'Agosto',
            'customer_email' => 'agosto@p5.test',
            'status' => OrderStatus::Paid,
            'total' => 40,
            'currency' => 'USD',
            'paid_at' => Carbon::parse('2026-08-10 12:00:00', 'America/La_Paz')->utc(),
        ]);

        $summary = app(DashboardMetricsService::class)->summary($leader->fresh());

        $this->assertSame(40.0, $summary['paid_store_sales']);
        $this->assertSame(40.0, $summary['closing']['sales']);

        $byMonth = collect($summary['series'])->keyBy('month');
        $this->assertSame(100.0, $byMonth['2026-07']['sales']);
        $this->assertSame(40.0, $byMonth['2026-08']['sales']);
        $this->assertNull($byMonth['2026-07']['personal']);
        $this->assertNull($byMonth['2026-08']['personal']);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function orgWithLeader(string $slug, string $name, string $email): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => $slug,
            'default_timezone' => 'America/La_Paz',
            'default_currency' => 'USD',
            'status' => 'active',
        ]);
        $leader = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'organization_id' => $organization->id,
        ]);
        $network = Network::query()->create([
            'owner_user_id' => $leader->id,
            'name' => $name,
            'slug' => 'net-'.$slug,
            'status' => 'active',
        ]);
        $leader->forceFill(['current_network_id' => $network->id])->save();

        return [$organization, $leader->fresh()];
    }

    private function memberWithVolume(
        Organization $organization,
        User $leader,
        string $code,
        float $personal,
        ?float $group,
    ): OrganizationMember {
        $member = OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $leader->id,
            'scope' => ConnectionScope::Organization->value,
            'external_code' => $code,
            'email' => $leader->email,
            'name' => $leader->name,
        ]);
        OrganizationVolume::query()->create([
            'organization_id' => $organization->id,
            'member_id' => $member->id,
            'scope' => ConnectionScope::Organization->value,
            'period' => '2026-08',
            'unit' => 'PV',
            'personal_volume' => $personal,
            'group_volume' => $group,
            'priority' => 20,
        ]);

        return $member;
    }
}
