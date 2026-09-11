<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'partner_user_id' => $this->partner_user_id,
            'channel' => $this->channel ?? 'ecommerce',
            'delivery' => $this->delivery ?? 'shipping',
            'payment_method' => $this->payment_method,
            'has_payment_voucher' => $this->hasPaymentVoucher(),
            'payment_voucher_url' => $this->ownerVoucherUrl($request),
            'payment_voucher_document_id' => $this->when(
                $this->viewerOwnsStore($request),
                $this->payment_voucher_document_id,
            ),
            'partner' => $this->when(
                $this->relationLoaded('partner') && $this->partner,
                fn () => [
                    'id' => $this->partner->id,
                    'name' => $this->partner->name,
                    'email' => $this->partner->email,
                ],
            ),
            'total' => $this->total,
            'shipping_fee' => $this->shipping_fee,
            'shipping_country' => $this->shipping_country,
            'shipping_department' => $this->shipping_department,
            'shipping_area' => $this->shipping_area,
            'shipping_address' => $this->shipping_address,
            'shipping_zone' => $this->shipping_zone,
            'shipping_eta_days' => $this->shipping_eta_days,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items'),
        ];
    }

    private function viewerOwnsStore(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $storeId = (int) $this->store_id;
        $ownedId = (int) ($user->store?->id ?: $user->store()->value('id'));

        return $storeId > 0 && $ownedId === $storeId;
    }

    private function ownerVoucherUrl(Request $request): ?string
    {
        if (! $this->hasPaymentVoucher() || ! $this->viewerOwnsStore($request)) {
            return null;
        }

        return url('/api/v1/my-store/orders/'.$this->id.'/voucher');
    }
}
