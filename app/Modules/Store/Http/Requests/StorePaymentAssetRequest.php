<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('store.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['qr', 'binance_qr'])],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
