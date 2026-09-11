<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Requests;

use App\Modules\MLM\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invitation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}
