<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Organization;

use App\Models\User;
use App\Modules\Organization\Actions\RunConnectionSync;
use App\Modules\Organization\Actions\UpsertOrganizationConnection;
use App\Modules\Organization\Connectors\SpreadsheetParser;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use App\Shared\Enums\ConnectionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrganizationConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_classifies_member_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "email,nombre\nana@test.com,Ana\nlucas@test.com,Lucas\n");

        $parsed = (new SpreadsheetParser)->parse($path, 'equipo.csv');

        $this->assertSame(2, $parsed['counts']['members']);
        @unlink($path);
    }

    public function test_api_driver_marks_connection_when_http_succeeds(): void
    {
        Http::fake(['https://backoffice.test/*' => Http::response(['ok' => true], 200)]);

        $organization = Organization::query()->create([
            'name' => 'HGW',
            'slug' => 'hgw-test',
            'default_timezone' => 'America/La_Paz',
            'default_currency' => 'USD',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $connection = OrganizationConnection::query()->create([
            'organization_id' => $organization->id,
            'scope' => ConnectionScope::Organization,
            'driver' => ConnectionDriver::Api,
            'name' => 'API',
            'status' => ConnectionStatus::Idle,
            'config' => ['base_url' => 'https://backoffice.test', 'health_path' => 'health'],
            'credentials' => ['token' => 'secret-token'],
        ]);

        $sync = app(RunConnectionSync::class)->handle($connection, $admin);

        $this->assertSame('completed', $sync->status->value);
        $this->assertSame('connected', $connection->fresh()->status->value);
        $this->assertArrayNotHasKey('credentials', $connection->fresh()->toArray());
    }

    public function test_leader_excel_stays_on_their_network_scope(): void
    {
        Storage::fake('local');
        $organization = Organization::query()->create([
            'name' => 'HGW',
            'slug' => 'hgw-net',
            'default_timezone' => 'America/La_Paz',
            'default_currency' => 'USD',
            'status' => 'active',
        ]);
        $leader = User::factory()->create(['organization_id' => $organization->id]);
        $network = \App\Modules\MLM\Models\Network::query()->create([
            'owner_user_id' => $leader->id,
            'name' => 'Red',
            'slug' => 'red-'.$leader->id,
            'status' => 'active',
        ]);
        $leader->forceFill(['current_network_id' => $network->id])->save();

        $file = UploadedFile::fake()->createWithContent('red.csv', "email,nombre\na@b.com,A\n");
        $connection = app(UpsertOrganizationConnection::class)->handle($organization, $leader->fresh(), [
            'driver' => ConnectionDriver::Excel->value,
            'scope' => ConnectionScope::Network->value,
            'name' => 'Mi red',
        ], $file);

        $this->assertSame('network', $connection->scope->value);
        $this->assertSame($network->id, $connection->network_id);

        $sync = app(RunConnectionSync::class)->handle($connection, $leader);
        $this->assertSame('completed', $sync->status->value);
        $this->assertGreaterThan(0, $sync->records['members'] ?? 0);
    }

    public function test_admin_excel_is_organization_scope_and_keeps_period(): void
    {
        Storage::fake('local');
        $organization = Organization::query()->create([
            'name' => 'DXN',
            'slug' => 'dxn-admin-feed',
            'default_timezone' => 'America/La_Paz',
            'default_currency' => 'USD',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent(
            'lideres.csv',
            "codigo,email,nombre,patrocinador,pv,gv,periodo\nL1,ana@dxn.test,Ana,,200,800,2026-08\n",
        );

        $connection = app(UpsertOrganizationConnection::class)->handle($organization, $admin, [
            'driver' => ConnectionDriver::Excel->value,
            'scope' => ConnectionScope::Organization->value,
            'config' => ['period' => '2026-08'],
        ], $file);

        $this->assertSame('organization', $connection->scope->value);
        $this->assertSame('2026-08', $connection->config['period'] ?? null);
        $this->assertNull($connection->network_id);

        $sync = app(RunConnectionSync::class)->handle($connection, $admin);
        $this->assertSame('completed', $sync->status->value);
        $this->assertGreaterThan(0, $sync->records['members'] ?? 0);
    }
}
