<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Shared\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.plans.manage') ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'price' => [$required, 'numeric', 'min:0'],
            'currency' => ['sometimes', 'in:'.Currencies::COMMISSION],
            'interval' => ['sometimes', 'in:month,year'],
            'commission_percentage' => [$required, 'numeric', 'between:0,100'],
            'features' => ['nullable', 'array'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'paddle_price_id' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
