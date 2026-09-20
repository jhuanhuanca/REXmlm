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
                'paddle_price_id' => 'pri_01m2vst8etcnwn8kt770c7zh5n',
                'paddle_intro_discount_id' => 'dsc_01m2vtdv6rwrb609nj92h4yqsb',
            ],
            [
                'name' => 'Intermedio',
                'slug' => 'intermedio',
                'price' => 49,
                'intro_price' => 1,
                'interval' => 'month',
                'sort_order' => 2,
                'paddle_price_id' => 'pri_01m2vt01r1fmx4wbkdx045kfqb',
                'paddle_intro_discount_id' => 'dsc_01m2vtfje8ys80417tqgzv9kyq',
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'price' => 69,
                'intro_price' => 1,
                'interval' => 'month',
                'sort_order' => 3,
                'paddle_price_id' => 'pri_01m2vt3fdene9xf8hgqp9eczb1',
                'paddle_intro_discount_id' => 'dsc_01m2vthhs71ra4hxw5hkzqy2vh',
            ],
            [
                'name' => 'Básico anual',
                'slug' => 'basico-anual',
                'price' => 290,
                'intro_price' => 1,
                'interval' => 'year',
                'sort_order' => 4,
                'paddle_price_id' => 'pri_01m2vswgfw22v5dak08r7kmnwk',
                'paddle_intro_discount_id' => 'dsc_01m2vtjnn07prh6wa6599rprcc',
            ],
            [
                'name' => 'Intermedio anual',
                'slug' => 'intermedio-anual',
                'price' => 490,
                'intro_price' => 1,
                'interval' => 'year',
                'sort_order' => 5,
                'paddle_price_id' => 'pri_01m2vt15bp9rp9aq4hgvwxrht8',
                'paddle_intro_discount_id' => 'dsc_01m2vtktk6zs54sf9h2dz00hen',
            ],
            [
                'name' => 'Premium anual',
                'slug' => 'premium-anual',
                'price' => 690,
                'intro_price' => 1,
                'interval' => 'year',
                'sort_order' => 6,
                'paddle_price_id' => 'pri_01m2vt4ft9dt18636z01ezb0cm',
                'paddle_intro_discount_id' => 'dsc_01m2vtmvkv1246hg1p9apgdrx4',
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
                    'paddle_price_id' => $plan['paddle_price_id'],
                    'paddle_intro_discount_id' => $plan['paddle_intro_discount_id'],
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
