<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'payment_method_id' => ['nullable', 'string'],
        ];
    }
}
