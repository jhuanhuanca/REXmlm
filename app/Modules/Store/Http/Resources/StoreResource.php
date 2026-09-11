<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Services\Catalog\CompanyBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
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
            'theme' => $this->theme,
            'settings' => $this->settings,
            'whatsapp' => data_get($this->settings, 'whatsapp'),
            'dropshipping' => data_get($this->settings, 'dropshipping'),
            'payments' => data_get($this->settings, 'payments'),
            'currency' => $this->currency(),
            'inventory' => [
                'low_stock_below' => $this->lowStockBelow(),
                'expiry_warning_days' => $this->expiryWarningDays(),
                'target_margin_percent' => $this->targetMarginPercent(),
            ],
            'is_active' => $this->is_active,
            'owner_name' => $this->when(
                $this->relationLoaded('user') && $this->user,
                fn () => $this->user->name,
                $this->name,
            ),
            'company' => $this->when(
                $this->relationLoaded('user'),
                fn () => CompanyBranding::forUser($this->user),
            ),
            'identity' => $this->when(
                $this->relationLoaded('user'),
                function () {
                    $landing = $this->user?->landingPage;
                    $content = is_array($landing?->content) ? $landing->content : [];

                    return [
                        'title' => $landing?->title,
                        'logo' => data_get($content, 'logo'),
                        'palette' => data_get($content, 'palette'),
                    ];
                },
            ),
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
