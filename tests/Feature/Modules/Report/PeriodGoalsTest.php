<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Report;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationVolume;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Modules\Report\Services\PeriodGoalsService;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PeriodGoalMetric;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodGoalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leader_goals_are_isolated_to_their_network(): void
    {
        [, $ana] = $this->orgWithLeader('hgw-g6', 'HGW', 'ana@g6.test');
        [, $mei] = $this->orgWithLeader('dxn-g6', 'DXN', 'mei@g6.test');

        $service = app(PeriodGoalsService::class);
        $service->upsert($ana, '2026-08', [
            ['metric' => PeriodGoalMetric::StoreSales->value, 'target' => 500],
        ]);

        $anaItems = collect($service->catalog($ana, '2026-08'))->keyBy('metric');
        $meiItems = collect($service->catalog($mei, '2026-08'))->keyBy('metric');

        $this->assertSame(500.0, $anaItems['store_sales']['target']);
        $this->assertNull($meiItems['store_sales']['target']);
        $this->assertSame('a', $anaItems['store_sales']['plane']);
        $this->assertSame('b', $anaItems['personal_volume']['plane']);
    }

    public function test_missing_company_volume_does_not_fake_zero_progress(): void
    {
        [, $leader] = $this->orgWithLeader('omni-g6', 'Omnilife', 'rosa@g6.test');
        $service = app(PeriodGoalsService::class);
        $service->upsert($leader, '2026-08', [
            ['metric' => PeriodGoalMetric::PersonalVolume->value, 'target' => 100],
            ['metric' => PeriodGoalMetric::GroupVolume->value, 'target' => 400],
        ]);

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $items = collect($report['goals']['items'])->keyBy('metric');

        $this->assertFalse($report['company_volume']['available']);
        $this->assertSame('unavailable', $items['personal_volume']['status']);
        $this->assertNull($items['personal_volume']['actual']);
        $this->assertNull($items['personal_volume']['progress']);
        $this->assertSame(100.0, $items['personal_volume']['target']);
        $this->assertNull($items['group_volume']['actual']);
        $this->assertNotSame(0, $items['personal_volume']['actual']);
    }

    public function test_store_sales_goal_uses_current_period_actuals(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 12:00:00', 'America/La_Paz'));
        [, $leader] = $this->orgWithLeader('face-g6', 'Face Global', 'kim@g6.test');
        $store = Store::query()->create([
            'user_id' => $leader->id,
            'network_id' => $leader->current_network_id,
            'name' => 'Tienda',
            'slug' => 'tienda-g6-'.$leader->id,
            'theme' => 'default',
            'is_active' => true,
        ]);
        Order::query()->create([
            'store_id' => $store->id,
            'network_id' => $leader->current_network_id,
            'customer_name' => 'Cliente',
            'customer_email' => 'c@g6.test',
            'status' => OrderStatus::Paid,
            'total' => 40,
            'currency' => 'USD',
            'paid_at' => Carbon::parse('2026-08-10 12:00:00', 'America/La_Paz')->utc(),
        ]);

        app(PeriodGoalsService::class)->upsert($leader, '2026-08', [
            ['metric' => PeriodGoalMetric::StoreSales->value, 'target' => 100],
            ['metric' => PeriodGoalMetric::PersonalVolume->value, 'target' => 80],
        ]);

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $items = collect($report['goals']['items'])->keyBy('metric');

        $this->assertSame(40.0, $items['store_sales']['actual']);
        $this->assertSame(40.0, $items['store_sales']['progress']);
        $this->assertSame('open', $items['store_sales']['status']);
        $this->assertSame('unavailable', $items['personal_volume']['status']);
        $this->assertNull($items['personal_volume']['progress']);
        $this->assertSame('2026-09', $report['goals']['next_period']);
        $this->assertNull(collect($report['goals']['next_items'])->firstWhere('metric', 'store_sales')['actual']);
    }

    public function test_imported_volume_can_meet_a_plane_b_goal(): void
    {
        [$organization, $leader] = $this->orgWithLeader('hgw-gv6', 'HGW', 'vol@g6.test');
        $this->memberWithVolume($organization, $leader, 'L6', 200, 800);
        app(PeriodGoalsService::class)->upsert($leader, '2026-08', [
            ['metric' => PeriodGoalMetric::PersonalVolume->value, 'target' => 150],
        ]);

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $item = collect($report['goals']['items'])->firstWhere('metric', 'personal_volume');

        $this->assertSame(200.0, $item['actual']);
        $this->assertSame('met', $item['status']);
        $this->assertSame(100.0, $item['progress']);
        $this->assertSame('PV', $item['unit']);
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
