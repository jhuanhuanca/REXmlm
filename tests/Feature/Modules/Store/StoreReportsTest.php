<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Store;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['cashier.secret' => null]);
    }

    public function test_leader_downloads_inventory_excel_and_pdf(): void
    {
        $ana = $this->leader('ana-rep@inv.test');
        Product::query()->create([
            'store_id' => $ana->store->id,
            'source' => ProductSource::Personal,
            'name' => 'Té de reporte',
            'slug' => 'te-de-reporte',
            'price' => 80,
            'purchase_cost' => 40,
            'stock' => 12,
            'currency' => 'USD',
            'is_active' => true,
            'is_published' => true,
        ]);

        Sanctum::actingAs($ana);

        $excel = $this->get('/api/v1/my-store/reports/inventory?format=xlsx');
        $excel->assertOk();
        $this->assertStringStartsWith('PK', $excel->getContent());
        $this->assertStringContainsString('spreadsheetml', (string) $excel->headers->get('content-type'));

        $pdf = $this->get('/api/v1/my-store/reports/inventory?format=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_leader_downloads_sales_excel(): void
    {
        $ana = $this->leader('ana-sales-rep@inv.test');
        Sanctum::actingAs($ana);

        $excel = $this->get('/api/v1/my-store/reports/sales?format=xlsx');
        $excel->assertOk();
        $this->assertStringStartsWith('PK', $excel->getContent());
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
            'slug' => 'net-rep-'.$user->id,
            'status' => 'active',
        ]);
        $user->forceFill(['current_network_id' => $network->id])->save();
        Store::query()->create([
            'user_id' => $user->id,
            'network_id' => $network->id,
            'name' => 'Tienda '.$user->id,
            'slug' => 'tienda-rep-'.$user->id,
            'theme' => 'default',
            'is_active' => true,
        ]);

        return $user->fresh(['store']);
    }
}
