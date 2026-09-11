<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MLM;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\MLM\Models\Referral;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Shared\Enums\ReferralStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
        Mail::fake();
    }

    public function test_platform_team_endpoint_stays_an_array(): void
    {
        $ana = $this->leader('ana-team@inv.test');
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/dashboard/team')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_leader_can_register_a_company_partner_and_see_it_in_roster(): void
    {
        $ana = $this->leader('ana-co@inv.test');
        Sanctum::actingAs($ana);

        $this->postJson('/api/v1/dashboard/team/company-partners', [
            'name' => 'Luis Empresa',
            'email' => 'luis-emp@inv.test',
            'phone' => '70000000',
            'code' => 'SC-1',
            'rank_name' => 'Bronce',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Luis Empresa')
            ->assertJsonPath('data.company_code', 'SC-1');

        $roster = $this->getJson('/api/v1/dashboard/team/roster')->assertOk();
        $roster->assertJsonPath('summary.company_partners', 1);
        $roster->assertJsonPath('summary.partners', 0);
        $this->assertSame('company', $roster->json('data.0.kind'));
        $this->assertTrue($roster->json('data.0.can_convert'));
        $this->assertSame('Bronce', $roster->json('data.0.rank_name'));
    }

    public function test_convert_company_partner_creates_invitation_not_a_leader(): void
    {
        $ana = $this->leader('ana-cv@inv.test');
        Sanctum::actingAs($ana);

        $memberId = $this->postJson('/api/v1/dashboard/team/company-partners', [
            'name' => 'Rosa',
            'email' => 'rosa-cv@inv.test',
        ])->assertCreated()->json('data.id');

        $convert = $this->postJson('/api/v1/dashboard/team/company-partners/'.$memberId.'/convert')
            ->assertCreated();
        $this->assertNotEmpty($convert->json('token'));

        $this->assertDatabaseHas('invitations', [
            'leader_id' => $ana->id,
            'email' => 'rosa-cv@inv.test',
            'status' => 'pending',
        ]);
        $this->assertSame(0, Referral::query()->where('referrer_id', $ana->id)->count());

        $this->getJson('/api/v1/dashboard/team/roster')
            ->assertJsonPath('data.0.invite_pending', true)
            ->assertJsonPath('data.0.kind', 'company');
    }

    public function test_company_sales_are_not_mixed_into_platform_team_sales(): void
    {
        $ana = $this->leader('ana-mix@inv.test');
        $partner = User::factory()->create(['email' => 'socio-mix@inv.test']);
        $partner->assignRole('partner');
        Referral::query()->create([
            'referrer_id' => $ana->id,
            'referred_id' => $partner->id,
            'network_id' => $ana->current_network_id,
            'level' => 1,
            'status' => ReferralStatus::Active,
        ]);

        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/dashboard/team/company-partners', [
            'name' => 'Solo empresa',
            'email' => 'solo-emp@inv.test',
        ])->assertCreated();

        $roster = $this->getJson('/api/v1/dashboard/team/roster')->assertOk();
        $this->assertSame(1, $roster->json('summary.partners'));
        $this->assertSame(1, $roster->json('summary.company_partners'));
        $this->assertEquals(0, $roster->json('summary.sales_month'));
    }

    public function test_leader_downloads_referral_and_company_reports(): void
    {
        $ana = $this->leader('ana-export@inv.test');
        Sanctum::actingAs($ana);
        $this->postJson('/api/v1/dashboard/team/company-partners', [
            'name' => 'Luis Empresa',
            'email' => 'luis-export@inv.test',
        ])->assertCreated();

        $excel = $this->get('/api/v1/dashboard/team/reports?kind=company&format=xlsx');
        $excel->assertOk();
        $this->assertStringStartsWith('PK', $excel->getContent());

        $pdf = $this->get('/api/v1/dashboard/team/reports?kind=referrals&format=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    private function leader(string $email): User
    {
        $organization = Organization::query()->create([
            'name' => 'HGW Test',
            'slug' => 'hgw-team-'.$email,
            'catalog_company_id' => 7,
            'default_timezone' => 'America/La_Paz',
            'default_currency' => 'USD',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'email' => $email,
            'country' => 'BO',
            'catalog_company_id' => 7,
            'catalog_company_name' => 'HGW Test',
            'organization_id' => $organization->id,
        ]);
        $user->assignRole('leader');
        $network = Network::query()->create([
            'owner_user_id' => $user->id,
            'name' => 'Red '.$user->id,
            'slug' => 'net-team-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();

        OrganizationMember::query()->create([
            'organization_id' => $organization->id,
            'network_id' => $network->id,
            'user_id' => $user->id,
            'scope' => 'network',
            'external_code' => 'L-'.$user->id,
            'email' => $email,
            'name' => $user->name,
            'status' => 'active',
        ]);

        return $user->fresh(['store', 'organization', 'ownedNetwork']);
    }
}
