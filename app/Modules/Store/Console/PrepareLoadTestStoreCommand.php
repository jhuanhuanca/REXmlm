<?php

declare(strict_types=1);

namespace App\Modules\Store\Console;

use App\Models\User;
use App\Modules\Landing\Models\LandingPage;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\NetworkStatus;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class PrepareLoadTestStoreCommand extends Command
{
    protected $signature = 'store:prepare-load-test
                            {--force : Permitir ejecutarlo en production}
                            {--json : Imprime solo JSON para k6}';

    protected $description = 'Crea o actualiza una tienda y landing dedicadas al load test (stock alto, sin catálogo remoto)';

    public function handle(): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('No se ejecuta en production sin --force. Usa staging o local.');

            return self::FAILURE;
        }

        if (! Role::query()->where('name', 'leader')->exists()) {
            $this->error('Falta el rol leader. Ejecuta: php artisan db:seed --class=RolePermissionSeeder');

            return self::FAILURE;
        }

        $email = 'loadtest-shop@rexmlm.invalid';
        $storeSlug = 'loadtest-shop';
        $landingSlug = 'loadtest-landing';
        $productSlug = 'loadtest-caja';

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => 'Load Test Shop',
                'email' => $email,
                'password' => str()->password(32),
                'country' => 'BO',
            ]);
            $user->assignRole('leader');
        }

        $network = Network::query()->firstOrCreate(
            ['slug' => 'loadtest-net'],
            [
                'owner_user_id' => $user->id,
                'name' => 'Red load test',
                'status' => NetworkStatus::Active,
            ],
        );

        $user->forceFill(['current_network_id' => $network->id])->save();

        $store = Store::query()->updateOrCreate(
            ['slug' => $storeSlug],
            [
                'user_id' => $user->id,
                'network_id' => $network->id,
                'name' => 'Tienda load test',
                'theme' => 'default',
                'is_active' => true,
                'settings' => [
                    'payments' => [
                        'qr_enabled' => true,
                        'deposit_enabled' => true,
                    ],
                ],
            ],
        );

        $product = Product::query()->updateOrCreate(
            [
                'store_id' => $store->id,
                'slug' => $productSlug,
            ],
            [
                'name' => 'Caja load test',
                'price' => 10,
                'currency' => 'USD',
                'stock' => 1_000_000,
                'is_active' => true,
                'is_published' => true,
                'source' => ProductSource::Personal,
                'fulfillment' => ProductFulfillment::Stock,
                'expires_at' => null,
            ],
        );

        LandingPage::query()->updateOrCreate(
            ['slug' => $landingSlug],
            [
                'user_id' => $user->id,
                'network_id' => $network->id,
                'title' => 'Landing load test',
                'template' => 'default',
                'content' => ['hero' => ['title' => 'Load test']],
                'is_published' => true,
            ],
        );

        $payload = [
            'base_path' => '/api/v1',
            'store_slug' => $store->slug,
            'product_slug' => $product->slug,
            'product_id' => $product->id,
            'landing_slug' => $landingSlug,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Tienda de load test lista. No uses este slug en un dominio público real.');
        $this->table(array_keys($payload), [array_values($payload)]);
        $this->comment('k6:  k6 run -e BASE_URL=http://rexmlm.test -e STORE_SLUG='.$store->slug.' -e PRODUCT_SLUG='.$product->slug.' -e PRODUCT_ID='.$product->id.' -e LANDING_SLUG='.$landingSlug.' ../loadtest/storefront.js');

        return self::SUCCESS;
    }
}
