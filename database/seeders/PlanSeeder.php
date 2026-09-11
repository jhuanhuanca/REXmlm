<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Subscription\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Plan Básico',
                'slug' => 'plan-basico',
                'price' => 29.99,
                'currency' => 'USD',
                'interval' => 'month',
                'commission_percentage' => 20,
                'features' => [
                    'store' => true,
                    'landing' => true,
                    'dashboard' => true,
                    'max_partners' => 50,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Plan Profesional',
                'slug' => 'plan-profesional',
                'price' => 59.99,
                'currency' => 'USD',
                'interval' => 'month',
                'commission_percentage' => 30,
                'features' => [
                    'store' => true,
                    'landing' => true,
                    'dashboard' => true,
                    'advanced_reports' => true,
                    'priority_support' => true,
                    'max_partners' => 500,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Plan Enterprise',
                'slug' => 'plan-enterprise',
                'price' => 99.99,
                'currency' => 'USD',
                'interval' => 'month',
                'commission_percentage' => 40,
                'features' => [
                    'store' => true,
                    'landing' => true,
                    'dashboard' => true,
                    'advanced_reports' => true,
                    'custom_domain' => true,
                    'api' => true,
                    'account_manager' => true,
                    'max_partners' => null,
                ],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
