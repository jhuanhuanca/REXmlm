<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportNetworkConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('report.generate') ?? false;
    }

    public function rules(): array
    {
        $maxKb = (int) config('rexmlm.closing.max_upload_kb', 10240);

        return [
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:csv,txt,tsv,xlsx'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
