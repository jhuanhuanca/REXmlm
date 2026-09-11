<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:5000'],
        ];
    }
}
