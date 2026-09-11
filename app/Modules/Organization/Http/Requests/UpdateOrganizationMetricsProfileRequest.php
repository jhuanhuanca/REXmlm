<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationMetricsProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'published' => ['sometimes', 'boolean'],
            'volume_unit' => ['sometimes', 'string', 'max:16'],
            'derive_personal_from_items' => ['sometimes', 'boolean'],
            'derive_group_from_downline' => ['sometimes', 'boolean'],
            'group_includes_personal' => ['sometimes', 'boolean'],
            'group_depth' => ['nullable', 'integer', 'min:1', 'max:40'],
            'min_personal' => ['nullable', 'numeric', 'min:0'],
            'min_group' => ['nullable', 'numeric', 'min:0'],
            'qualified_lines' => ['nullable', 'integer', 'min:0', 'max:100'],
            'line_min_volume' => ['nullable', 'numeric', 'min:0'],
            'maintenance' => ['sometimes', 'boolean'],
            'ranks' => ['sometimes', 'array'],
            'ranks.*.code' => ['nullable', 'string', 'max:64'],
            'ranks.*.name' => ['required', 'string', 'max:120'],
            'ranks.*.min_personal' => ['nullable', 'numeric', 'min:0'],
            'ranks.*.min_group' => ['nullable', 'numeric', 'min:0'],
            'ranks.*.min_lines' => ['nullable', 'integer', 'min:0', 'max:100'],
            'ranks.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
