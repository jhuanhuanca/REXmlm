<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'partner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'payment_method' => ['nullable', 'string', 'in:qr,qr_binance,deposit,transfer'],
            'shipping_country' => ['nullable', 'string', 'size:2'],
            'shipping_department' => ['nullable', 'string', 'max:120'],
            'shipping_area' => ['nullable', 'string', 'max:120'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'voucher' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (is_array($decoded)) {
                $this->merge(['items' => $decoded]);
            }
        }

        $country = $this->input('shipping_country');
        if (is_string($country) && $country !== '') {
            $this->merge(['shipping_country' => strtoupper(trim($country))]);
        }
    }
}
