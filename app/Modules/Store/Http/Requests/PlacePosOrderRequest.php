<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlacePosOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') && $this->user()?->store !== null;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'delivery' => ['required', 'string', 'in:pickup,shipping'],
            'shipping_country' => ['nullable', 'string', 'size:2'],
            'shipping_department' => ['nullable', 'string', 'max:120'],
            'shipping_area' => ['nullable', 'string', 'max:120'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'mark_paid' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $country = $this->input('shipping_country');
        if (is_string($country) && $country !== '') {
            $this->merge(['shipping_country' => strtoupper(trim($country))]);
        }

        if (! $this->filled('delivery')) {
            $this->merge(['delivery' => 'pickup']);
        }
    }
}
