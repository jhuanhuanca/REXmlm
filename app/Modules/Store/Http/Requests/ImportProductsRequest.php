<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ImportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
        ];
    }
}
