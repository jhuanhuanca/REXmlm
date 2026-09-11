<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Organization;

use App\Models\User;
use App\Modules\Organization\Actions\RunConnectionSync;
use App\Modules\Organization\Actions\UpsertOrganizationConnection;
use App\Modules\Organization\Canonical\LeaderCanonicalSlice;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationVolume;
use App\Modules\Report\Services\MonthlyClosingService;
use App\Modules\MLM\Models\Network;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrganizationCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_hgw_dxn_omnilife_and_face_global_normalize_to_the_same_tables(): void
    {
        Storage::fake('local');

        $cases = [
            [
                'slug' => 'hgw-can',
                'name' => 'HGW',
                'csv' => "codigo,nombre,correo,patrocinador,pv,gv,rango\nL1,Ana,ana@hgw.test,,100,400,Plata\nS1,Luis,luis@hgw.test,L1,20,20,Bronce\n",
                'leaderEmail' => 'ana@hgw.test',
                'personal' => 100.0,
                'group' => 400.0,
                'unit' => 'PV',
                'members' => 2,
            ],
            [
                'slug' => 'dxn-can',
                'name' => 'DXN',
                'csv' => "Member Code,Name,Email,Sponsor ID,PV,SV,Rank\nDX1,Mei,mei@dxn.test,,55,18,Star\nDX2,Omar,omar@dxn.test,DX1,10,4,Member\n",
                'leaderEmail' => 'mei@dxn.test',
                'personal' => 55.0,
                'group' => null,
                'unit' => 'PV',
                'members' => 2,
            ],
            [
                'slug' => 'omnilife-can',
                'name' => 'Omnilife',
                'csv' => "codigo,nombre,correo,patrocinador,puntos,rango\nOM1,Rosa,rosa@omni.test,,80,Empresario\nOM2,Tito,tito@omni.test,OM1,15,Distribuidor\n",
                'leaderEmail' => 'rosa@omni.test',
                'personal' => 80.0,
                'group' => null,
                'unit' => 'puntos',
                'members' => 2,
            ],
            [
                'slug' => 'face-can',
                'name' => 'Face Global',
                'csv' => "Distributor ID,Name,Email,Sponsor,PV,GV\nFG1,Kim,kim@face.test,,30,120\nFG2,Paz,paz@face.test,FG1,8,8\n",
                'leaderEmail' => 'kim@face.test',
                'personal' => 30.0,
                'group' => 120.0,
                'unit' => 'PV',
                'members' => 2,
            ],
        ];

        foreach ($cases as $case) {
            [$organization, $leader] = $this->orgWithLeader($case['slug'], $case['name'], $case['leaderEmail']);
            $this->importCsv($organization, $leader, $case['csv'], ConnectionScope::Organization, ['period' => '2026-08']);

            $this->assertSame(
                $case['members'],
                OrganizationMember::query()->where('organization_id', $organization->id)->count(),
                $case['name'].' members',
            );

            $slice = app(LeaderCanonicalSlice::class)->for($leader->fresh(), '2026-08');
            $this->assertTrue($slice['available'], $case['name'].' volume available');
            $this->assertSame($case['personal'], $slice['personal'], $case['name'].' PV');
            $this->assertSame($case['group'], $slice['group'], $case['name'].' GV');
            $this->assertSame($case['unit'], $slice['unit'], $case['name'].' unit');
            $this->assertSame(2, $slice['members'], $case['name'].' downline size');
        }

        $this->assertSame(4, Organization::query()->count());
        $hgwId = Organization::query()->where('slug', 'hgw-can')->value('id');
        $this->assertSame(2, OrganizationMember::query()->where('organization_id', $hgwId)->count());
        $this->assertSame(8, OrganizationMember::query()->count());
    }

    public function test_companies_do_not_leak_members_across_organizations(): void
    {
        Storage::fake('local');
        [$hgw, $ana] = $this->orgWithLeader('hgw-iso', 'HGW', 'ana@iso.test');
        [$dxn, $mei] = $this->orgWithLeader('dxn-iso', 'DXN', 'mei@iso.test');

        $this->importCsv($hgw, $ana, "codigo,correo,pv\n1001,ana@iso.test,10\n", ConnectionScope::Organization, ['period' => '2026-08']);
        $this->importCsv($dxn, $mei, "Member Code,Email,PV\n1001,mei@iso.test,999\n", ConnectionScope::Organization, ['period' => '2026-08']);

        $slice = app(LeaderCanonicalSlice::class)->for($ana->fresh(), '2026-08');
        $this->assertSame(10.0, $slice['personal']);
        $this->assertNotSame(999.0, $slice['personal']);
    }

    public function test_official_volume_beats_network_import(): void
    {
        Storage::fake('local');
        [$organization, $leader] = $this->orgWithLeader('hgw-prio', 'HGW', 'prio@test.com');

        $this->importCsv(
            $organization,
            $leader,
            "codigo,correo,pv\nL9,prio@test.com,80\n",
            ConnectionScope::Organization,
            ['period' => '2026-08'],
        );
        $this->importCsv(
            $organization,
            $leader,
            "codigo,correo,pv\nL9,prio@test.com,3\n",
            ConnectionScope::Network,
            ['period' => '2026-08'],
        );

        $this->assertSame(2, OrganizationVolume::query()->where('organization_id', $organization->id)->count());
        $slice = app(LeaderCanonicalSlice::class)->for($leader->fresh(), '2026-08');
        $this->assertSame(80.0, $slice['personal']);
        $this->assertSame('organization', $slice['source']);
    }

    public function test_closing_does_not_fake_zero_pv_when_empty(): void
    {
        [$organization, $leader] = $this->orgWithLeader('hgw-empty', 'HGW', 'empty@test.com');

        $report = app(MonthlyClosingService::class)->build($leader->fresh(), '2026-08');

        $this->assertFalse($report['company_volume']['available']);
        $this->assertNull($report['company_volume']['personal']);
        $this->assertNull($report['company_volume']['group']);
        $this->assertSame($organization->id, $report['organization']['id'] ?? $leader->organization_id);
    }

    public function test_api_json_from_any_company_normalizes_members(): void
    {
        Http::fake([
            'https://backoffice.dxn.test/health' => Http::response(['ok' => true], 200),
            'https://backoffice.dxn.test/members' => Http::response([
                'members' => [
                    ['Member Code' => 'DX9', 'Email' => 'api@dxn.test', 'PV' => 22, 'SV' => 7],
                ],
            ], 200),
        ]);

        [$organization, $leader] = $this->orgWithLeader('dxn-api', 'DXN', 'api@dxn.test');
        $connection = app(UpsertOrganizationConnection::class)->handle($organization, $leader, [
            'driver' => ConnectionDriver::Api->value,
            'scope' => ConnectionScope::Organization->value,
            'name' => 'API DXN',
            'config' => [
                'base_url' => 'https://backoffice.dxn.test',
                'health_path' => 'health',
                'members_path' => 'members',
                'period' => '2026-08',
            ],
        ]);

        $sync = app(RunConnectionSync::class)->handle($connection, $leader);
        $this->assertSame('completed', $sync->status->value);

        $slice = app(LeaderCanonicalSlice::class)->for($leader->fresh(), '2026-08');
        $this->assertTrue($slice['available']);
        $this->assertSame(22.0, $slice['personal']);
        $this->assertSame(7.0, $slice['sales_volume']);
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

    /**
     * @param  array<string, mixed>  $config
     */
    private function importCsv(
        Organization $organization,
        User $actor,
        string $csv,
        ConnectionScope $scope,
        array $config = [],
    ): void {
        $file = UploadedFile::fake()->createWithContent('data.csv', $csv);
        $connection = app(UpsertOrganizationConnection::class)->handle($organization, $actor, [
            'driver' => ConnectionDriver::Excel->value,
            'scope' => $scope->value,
            'name' => $scope === ConnectionScope::Network ? 'Red' : 'Empresa',
            'config' => $config,
        ], $file);

        $sync = app(RunConnectionSync::class)->handle($connection, $actor);
        $this->assertSame('completed', $sync->status->value, (string) $sync->message);
    }
}
