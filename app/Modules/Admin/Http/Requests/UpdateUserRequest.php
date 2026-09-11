<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Shared\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.users.manage') ?? false;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,'.$userId],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'password' => ['sometimes', 'confirmed', 'min:8'],
        ];
    }
}
