<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Services\Catalog\CatalogClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use RuntimeException;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'catalog_company_id' => [...$leaderOnly, 'nullable', 'integer'],
            'catalog_company_name' => ['nullable', 'string', 'max:255'],
            'catalog_rank_id' => [...$leaderOnly, 'nullable', 'integer'],
            'catalog_rank_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('invitation_token') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = (int) $this->input('catalog_company_id');
            $rankId = (int) $this->input('catalog_rank_id');

            try {
                $affiliation = app(CatalogClient::class)->findCompanyRank($companyId, $rankId);
            } catch (RuntimeException) {
                $validator->errors()->add(
                    'catalog_company_id',
                    'No se pudo cargar el catálogo de empresas. Inténtalo de nuevo.',
                );

                return;
            }

            if ($affiliation === null) {
                $validator->errors()->add(
                    'catalog_rank_id',
                    'Elige una empresa y un rango válido de esa empresa.',
                );

                return;
            }

            $this->merge([
                'catalog_company_name' => $affiliation['company_name'],
                'catalog_rank_name' => $affiliation['rank_name'],
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.required_without' => 'Selecciona tu país.',
            'catalog_company_id.required_without' => 'Selecciona la empresa.',
            'catalog_rank_id.required_without' => 'Selecciona tu rango en esa empresa.',
        ];
    }
}
