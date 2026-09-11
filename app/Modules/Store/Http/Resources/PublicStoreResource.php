<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Services\Catalog\CompanyBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicStoreResource extends JsonResource
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
            'whatsapp' => data_get($this->settings, 'whatsapp'),
            'payments' => $this->publicPayments(),
            'dropshipping' => $this->publicShipping(),
            'currency' => $this->currency(),
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
            'products' => PublicProductResource::collection($this->whenLoaded('products')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicPayments(): ?array
    {
        $payments = data_get($this->settings, 'payments');

        if (! is_array($payments)) {
            return null;
        }

        return [
            'qr_enabled' => (bool) ($payments['qr_enabled'] ?? false),
            'qr_image' => $payments['qr_image'] ?? null,
            'qr_notes' => $payments['qr_notes'] ?? null,
            'binance_enabled' => (bool) ($payments['binance_enabled'] ?? false),
            'binance_image' => $payments['binance_image'] ?? null,
            'binance_pay_id' => $payments['binance_pay_id'] ?? null,
            'binance_notes' => $payments['binance_notes'] ?? null,
            'deposit_enabled' => (bool) ($payments['deposit_enabled'] ?? false),
            'deposit_notes' => $payments['deposit_notes'] ?? null,
            'transfer_enabled' => (bool) ($payments['transfer_enabled'] ?? false),
            'transfer_notes' => $payments['transfer_notes'] ?? null,
            'bank_name' => $payments['bank_name'] ?? null,
            'bank_account_holder' => $payments['bank_account_holder'] ?? null,
            'bank_account_number' => $payments['bank_account_number'] ?? null,
            'bank_account_type' => $payments['bank_account_type'] ?? null,
            'bank_document' => $payments['bank_document'] ?? null,
        ];
    }

    /**
     * Tarifas de envío para el checkout. Sin origen del almacén.
     *
     * @return array<string, mixed>|null
     */
    private function publicShipping(): ?array
    {
        $shipping = data_get($this->settings, 'dropshipping');

        if (! is_array($shipping)) {
            return null;
        }

        $zones = [];
        foreach ($shipping['zones'] ?? [] as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            $zones[] = [
                'country' => $zone['country'] ?? null,
                'department' => $zone['department'] ?? null,
                'area' => $zone['area'] ?? null,
                'fee' => $zone['fee'] ?? null,
                'eta_days' => $zone['eta_days'] ?? null,
                'label' => $zone['label'] ?? null,
            ];
        }

        return [
            'enabled' => (bool) ($shipping['enabled'] ?? false),
            'handling_fee' => $shipping['handling_fee'] ?? null,
            'free_shipping_from' => $shipping['free_shipping_from'] ?? null,
            'local_fee' => $shipping['local_fee'] ?? null,
            'department_fee' => $shipping['department_fee'] ?? null,
            'national_fee' => $shipping['national_fee'] ?? null,
            'international_fee' => $shipping['international_fee'] ?? null,
            'local_days' => $shipping['local_days'] ?? null,
            'department_days' => $shipping['department_days'] ?? null,
            'national_days' => $shipping['national_days'] ?? null,
            'international_days' => $shipping['international_days'] ?? null,
            'notes' => $shipping['notes'] ?? null,
            'zones' => $zones,
        ];
    }
}
