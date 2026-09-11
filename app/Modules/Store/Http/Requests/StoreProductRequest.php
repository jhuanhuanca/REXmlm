<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Product;
use App\Shared\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($this->isMethod('post')) {
            return $user->can('create', Product::class);
        }

        return $user->can('product.manage') && $user->store !== null;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'technical_sheet' => ['nullable', 'string'],
            'price' => [$required, 'numeric', 'min:0'],
            'purchase_cost' => ['sometimes', 'numeric', 'min:0'],
            'source' => ['sometimes', Rule::enum(ProductSource::class)],
            'incentive_product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('store_id', $this->user()?->store?->id ?? 0)
                        ->where('source', ProductSource::Incentive->value);
                }),
            ],
            'incentive_qty' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(Currencies::CODES)],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'fulfillment' => ['sometimes', Rule::enum(ProductFulfillment::class)],
            'expires_at' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'dropship_url' => ['nullable', 'string', 'max:2048'],
            'dropship_sku' => ['nullable', 'string', 'max:120'],
            'store_category_id' => [
                'nullable',
                'integer',
                Rule::exists('store_product_categories', 'id')->where(
                    fn ($query) => $query->where('store_id', $this->user()?->store?->id ?? 0),
                ),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('incentive_product_id') && $this->input('incentive_product_id') === '') {
            $this->merge(['incentive_product_id' => null]);
        }

        if ($this->filled('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }

        if ($this->has('fulfillment')) {
            $raw = strtolower((string) $this->input('fulfillment'));
            if (in_array($raw, ['dropshipping', 'drop', 'envio'], true)) {
                $this->merge(['fulfillment' => ProductFulfillment::Dropship->value]);
            }
        }
    }
}
