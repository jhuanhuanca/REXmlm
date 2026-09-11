<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('team.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'code' => ['nullable', 'string', 'max:60'],
            'rank_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
