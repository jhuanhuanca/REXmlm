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
            'catalog_company_id' => ['nullable', 'integer'],
            'catalog_company_name' => ['nullable', 'string', 'max:255'],
            'catalog_rank_id' => ['nullable', 'integer'],
            'catalog_rank_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
