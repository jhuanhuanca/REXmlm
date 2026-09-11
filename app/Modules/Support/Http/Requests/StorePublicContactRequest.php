<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'subject' => ['nullable', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
