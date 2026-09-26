<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoogleAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $countryCodes = array_column(config('rexmlm.countries'), 'code');

        return [
            'id_token' => ['required', 'string'],
            'invitation_token' => ['nullable', 'string', 'size:64'],
            'country' => ['nullable', 'string', 'size:2', Rule::in($countryCodes)],
            'catalog_company_name' => ['nullable', 'string', 'max:255'],
            'catalog_rank_name' => ['nullable', 'string', 'max:255'],
        ];
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

    private function normalizedName(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value !== '' ? $value : null;
    }
}
