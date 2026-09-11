<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignInventoryRequest extends FormRequest
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
            'product_id' => ['required', 'integer'],
            'partner_user_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
