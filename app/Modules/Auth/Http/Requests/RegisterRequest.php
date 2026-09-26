<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('invitation_token')) {
            return;
        }

        $this->merge([
            'catalog_company_id' => null,
            'catalog_rank_id' => null,
            'catalog_company_name' => $this->normalizedName('catalog_company_name'),
            'catalog_rank_name' => $this->normalizedName('catalog_rank_name'),
        ]);
    }

    public function rules(): array
    {
        $countryCodes = array_column(config('rexmlm.countries'), 'code');
        $leaderOnly = ['required_without:invitation_token'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'invitation_token' => ['nullable', 'string', 'size:64'],
            'country' => [...$leaderOnly, 'nullable', 'string', 'size:2', Rule::in($countryCodes)],
            'catalog_company_name' => [...$leaderOnly, 'nullable', 'string', 'max:255'],
            'catalog_rank_name' => [...$leaderOnly, 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required_without' => 'Selecciona tu país.',
            'catalog_company_name.required_without' => 'Escribe el nombre de tu empresa.',
            'catalog_rank_name.required_without' => 'Escribe tu rango.',
        ];
    }

    private function normalizedName(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value !== '' ? $value : null;
    }
}
