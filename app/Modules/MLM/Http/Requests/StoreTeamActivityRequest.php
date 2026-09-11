<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Requests;

use App\Shared\Enums\TeamActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamActivityRequest extends FormRequest
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
            'type' => ['nullable', 'string', Rule::enum(TeamActivityType::class)],
            'body' => ['required', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
