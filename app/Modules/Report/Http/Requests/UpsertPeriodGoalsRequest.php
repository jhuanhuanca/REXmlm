<?php

declare(strict_types=1);

namespace App\Modules\Report\Http\Requests;

use App\Shared\Enums\PeriodGoalMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertPeriodGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('report.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'goals' => ['present', 'array'],
            'goals.*.metric' => ['required', 'string', Rule::enum(PeriodGoalMetric::class)],
            'goals.*.target' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'next_goals' => ['sometimes', 'array'],
            'next_goals.*.metric' => ['required', 'string', Rule::enum(PeriodGoalMetric::class)],
            'next_goals.*.target' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
