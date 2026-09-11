<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Requests;

use App\Modules\Commission\Models\WithdrawalRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WithdrawalRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'collect_all' => ['sometimes', 'boolean'],
            'amount' => ['exclude_if:collect_all,true', 'required', 'numeric', 'min:0.01'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('collect_all')) {
            $this->merge([
                'collect_all' => $this->boolean('collect_all'),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Indica el monto a retirar o elige cobrar todo.',
            'password.required' => 'Confirma la solicitud con tu contraseña del sistema.',
        ];
    }
}
