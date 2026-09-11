<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Shared\Enums\ConnectionDriver;
use App\Shared\Enums\ConnectionScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        $maxKb = (int) config('rexmlm.closing.max_upload_kb', 10240);

        return [
            'id' => ['nullable', 'integer'],
            'driver' => ['required', Rule::enum(ConnectionDriver::class)],
            'scope' => ['nullable', Rule::enum(ConnectionScope::class)],
            'name' => ['nullable', 'string', 'max:120'],
            'config' => ['nullable', 'array'],
            'config.base_url' => ['nullable', 'url', 'max:500'],
            'config.health_path' => ['nullable', 'string', 'max:200'],
            'config.url' => ['nullable', 'url', 'max:500'],
            'config.notes' => ['nullable', 'string', 'max:2000'],
            'config.period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'credentials' => ['nullable', 'array'],
            'credentials.token' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:'.$maxKb, 'mimes:csv,txt,tsv,xlsx,xls,pdf,zip,json'],
        ];
    }
}
