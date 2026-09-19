<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Http\Resources;

use App\Modules\Subscription\Services\PlanEntitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => $this->price,
            'intro_price' => $this->intro_price ?? 1,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'commission_percentage' => $this->commission_percentage,
            'features' => $this->features,
            'entitlements' => PlanEntitlements::of($this->resource),
            'recommended' => str_contains((string) $this->slug, 'intermedio'),
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order ?? 0,
            'stripe_price_id' => $this->when(
                $request->user()?->hasRole('admin'),
                $this->stripe_price_id
            ),
            'paddle_price_id' => $this->when(
                $request->user()?->hasRole('admin'),
                $this->paddle_price_id ?: $this->stripe_price_id
            ),
            'paddle_intro_discount_id' => $this->when(
                $request->user()?->hasRole('admin'),
                $this->paddle_intro_discount_id
            ),
        ];
    }
}
