<?php

declare(strict_types=1);

namespace App\Modules\MLM\Http\Requests;

use App\Shared\Enums\TeamCrmStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
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
            'crm_stage' => ['sometimes', 'string', Rule::enum(TeamCrmStage::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'follow_up_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
