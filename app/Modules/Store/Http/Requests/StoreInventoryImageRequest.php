<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Enums\ProductSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('product.manage') && $user->store !== null;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
            'kind' => ['sometimes', Rule::in([ProductSource::Personal->value, ProductSource::Incentive->value])],
        ];
    }
}
