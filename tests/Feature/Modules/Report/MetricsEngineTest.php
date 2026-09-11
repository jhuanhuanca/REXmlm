<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Report;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationOrder;
use App\Modules\Organization\Models\OrganizationOrderItem;
use App\Modules\Organization\Models\OrganizationSponsor;
use App\Modules\Organization\Models\OrganizationVolume;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricsEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublished_profile_never_claims_qualification(): void
    {
        [$organization, $leader] = $this->orgWithLeader('hgw-m4', 'HGW', 'ana@m4.test');
        $this->memberWithVolume($organization, $leader, 'L1', 200, 800);

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');

        $this->assertSame('unpublished', $report['qualification']['status']);
        $this->assertFalse($report['qualification']['official']);
        $this->assertSame(200.0, $report['company_volume']['personal']);
        $this->assertNull($report['rank_progress']['progress']);
        $this->assertSame('tienda REXmlm, no es PV', $report['store_proxy']['label']);
        $this->assertNotEquals($report['store_proxy']['personal'], $report['company_volume']['personal']);
    }

    public function test_published_rules_qualify_when_thresholds_are_met(): void
    {
        [$organization, $leader] = $this->orgWithLeader('dxn-m4', 'DXN', 'mei@m4.test');
        $this->memberWithVolume($organization, $leader, 'DX1', 100, 300);
        $organization->forceFill([
            'metrics_profile' => [
                'published' => true,
                'min_personal' => 80,
                'min_group' => 200,
                'ranks' => [
                    ['name' => 'Star', 'min_personal' => 50, 'min_group' => 100, 'sort_order' => 1],
                    ['name' => 'Gold', 'min_personal' => 150, 'min_group' => 500, 'sort_order' => 2],
                ],
            ],
        ])->save();

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');

        $this->assertSame('qualified', $report['qualification']['status']);
        $this->assertTrue($report['qualification']['official']);
        $this->assertSame('Star', $report['rank_progress']['current']['name']);
        $this->assertSame('Gold', $report['rank_progress']['next']['name']);
        $this->assertNotNull($report['rank_progress']['progress']);
        $this->assertTrue($report['rank_progress']['progress'] < 100);
    }

    public function test_missing_volume_is_insufficient_data_not_a_fake_zero(): void
    {
        [$organization, $leader] = $this->orgWithLeader('omni-m4', 'Omnilife', 'rosa@m4.test');
        $organization->forceFill([
            'metrics_profile' => [
                'published' => true,
                'min_personal' => 50,
            ],
        ])->save();

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');

        $this->assertFalse($report['company_volume']['available']);
        $this->assertNull($report['company_volume']['personal']);
        $this->assertSame('insufficient_data', $report['qualification']['status']);
        $this->assertNull($report['rank_progress']['progress']);
    }

    public function test_group_is_not_summed_unless_the_company_allows_it(): void
    {
        [$organization, $leader] = $this->orgWithLeader('face-m4', 'Face Global', 'kim@m4.test');
        $root = $this->memberWithVolume($organization, $leader, 'FG1', 30, null);
        $child = OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'scope' => ConnectionScope::Organization->value,
            'external_code' => 'FG2',
            'name' => 'Paz',
            'sponsor_code' => 'FG1',
            'sponsor_member_id' => $root->id,
        ]);
        OrganizationSponsor::query()->create([
            'organization_id' => $organization->id,
            'member_id' => $child->id,
            'sponsor_member_id' => $root->id,
        ]);
        OrganizationVolume::query()->create([
            'organization_id' => $organization->id,
            'member_id' => $child->id,
            'scope' => ConnectionScope::Organization->value,
            'period' => '2026-08',
            'unit' => 'PV',
            'personal_volume' => 8,
            'priority' => 20,
        ]);

        $withoutRule = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $this->assertSame(30.0, $withoutRule['company_volume']['personal']);
        $this->assertNull($withoutRule['company_volume']['group']);

        $organization->forceFill([
            'metrics_profile' => [
                'derive_group_from_downline' => true,
                'group_includes_personal' => true,
            ],
        ])->save();

        $withRule = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $this->assertSame(38.0, $withRule['company_volume']['group']);
        $this->assertSame('derived_downline', $withRule['company_volume']['group_origin']);
        $this->assertSame('unpublished', $withRule['qualification']['status']);
    }

    public function test_personal_can_be_derived_from_order_items_when_allowed(): void
    {
        [$organization, $leader] = $this->orgWithLeader('hgw-items', 'HGW', 'items@m4.test');
        $member = OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $leader->id,
            'scope' => ConnectionScope::Organization->value,
            'external_code' => 'L9',
            'email' => $leader->email,
            'name' => $leader->name,
        ]);
        $order = OrganizationOrder::query()->create([
            'organization_id' => $organization->id,
            'member_id' => $member->id,
            'scope' => ConnectionScope::Organization->value,
            'external_code' => 'P-1',
            'period' => '2026-08',
            'total' => 40,
        ]);
        OrganizationOrderItem::query()->create([
            'organization_id' => $organization->id,
            'order_id' => $order->id,
            'sku' => 'TEA',
            'pv' => 12.5,
            'quantity' => 1,
        ]);
        $organization->forceFill([
            'metrics_profile' => ['derive_personal_from_items' => true],
        ])->save();

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');
        $this->assertTrue($report['company_volume']['available']);
        $this->assertSame(12.5, $report['company_volume']['personal']);
        $this->assertSame('derived_items', $report['company_volume']['personal_origin']);
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
