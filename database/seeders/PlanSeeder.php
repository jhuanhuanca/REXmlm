<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Services\PlanEntitlements;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Básico',
                'slug' => 'basico',
                'price' => 29,
                'intro_price' => 1,
                'interval' => 'month',
                'sort_order' => 1,
            ],
            [
                'name' => 'Intermedio',
                'slug' => 'intermedio',
                'price' => 49,
                'intro_price' => 1,
                'interval' => 'month',
                'sort_order' => 2,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'price' => 69,
                'intro_price' => 1,
                'interval' => 'month',
                'sort_order' => 3,
            ],
            [
                'name' => 'Básico anual',
                'slug' => 'basico-anual',
                'price' => 290,
                'intro_price' => 1,
                'interval' => 'year',
                'sort_order' => 4,
            ],
            [
                'name' => 'Intermedio anual',
                'slug' => 'intermedio-anual',
                'price' => 490,
                'intro_price' => 1,
                'interval' => 'year',
                'sort_order' => 5,
            ],
            [
                'name' => 'Premium anual',
                'slug' => 'premium-anual',
                'price' => 690,
                'intro_price' => 1,
                'interval' => 'year',
                'sort_order' => 6,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'price' => $plan['price'],
                    'intro_price' => $plan['intro_price'],
                    'currency' => 'USD',
                    'interval' => $plan['interval'],
                    'commission_percentage' => 10,
                    'features' => PlanEntitlements::catalog($plan['slug']),
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                ],
            );
        }

        Plan::query()
            ->whereNotIn('slug', array_column($plans, 'slug'))
            ->update(['is_active' => false]);
    }
}
