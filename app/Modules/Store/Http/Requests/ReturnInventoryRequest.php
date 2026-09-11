<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->user()?->store;

        return $store !== null && $this->user()->can('update', $store);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
        ];
    }
}
