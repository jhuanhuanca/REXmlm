<?php

declare(strict_types=1);

namespace App\Modules\Landing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLandingAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $landing = $user?->landingPage;

        return $landing !== null
            && $user->can('landing.manage')
            && $user->can('update', $landing);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['photo', 'logo', 'background', 'reasons'])],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ];
    }
}
