<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Shared\Support\Currencies;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->user()?->store;

        return $store !== null && $this->user()->can('update', $store);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'theme' => ['sometimes', 'string', 'max:50'],
            'settings' => ['sometimes', 'array'],
            'settings.currency' => ['nullable', 'string', Rule::in(Currencies::CODES)],
            'settings.whatsapp' => ['nullable', 'string', 'max:30'],
            'settings.dropshipping' => ['sometimes', 'array'],
            'settings.dropshipping.enabled' => ['sometimes', 'boolean'],
            'settings.dropshipping.origin_country' => ['nullable', 'string', 'size:2'],
            'settings.dropshipping.origin_department' => ['nullable', 'string', 'max:120'],
            'settings.dropshipping.origin_area' => ['nullable', 'string', 'max:120'],
            'settings.dropshipping.handling_fee' => ['nullable', 'numeric', 'min:0'],
            'settings.dropshipping.free_shipping_from' => ['nullable', 'numeric', 'min:0'],
            'settings.dropshipping.local_fee' => ['nullable', 'numeric', 'min:0'],
            'settings.dropshipping.department_fee' => ['nullable', 'numeric', 'min:0'],
            'settings.dropshipping.national_fee' => ['nullable', 'numeric', 'min:0'],
            'settings.dropshipping.international_fee' => ['nullable', 'numeric', 'min:0'],
            'settings.dropshipping.local_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'settings.dropshipping.department_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'settings.dropshipping.national_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'settings.dropshipping.international_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'settings.dropshipping.notes' => ['nullable', 'string', 'max:2000'],
            'settings.dropshipping.zones' => ['sometimes', 'array', 'max:80'],
            'settings.dropshipping.zones.*.country' => ['required', 'string', 'size:2'],
            'settings.dropshipping.zones.*.department' => ['nullable', 'string', 'max:120'],
            'settings.dropshipping.zones.*.area' => ['nullable', 'string', 'max:120'],
            'settings.dropshipping.zones.*.fee' => ['required', 'numeric', 'min:0'],
            'settings.dropshipping.zones.*.eta_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'settings.dropshipping.zones.*.label' => ['nullable', 'string', 'max:160'],
            'settings.inventory' => ['sometimes', 'array'],
            'settings.inventory.low_stock_below' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'settings.inventory.expiry_warning_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'settings.inventory.target_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:95'],
            'settings.payments' => ['sometimes', 'array'],
            'settings.payments.qr_enabled' => ['sometimes', 'boolean'],
            'settings.payments.qr_image' => ['nullable', 'string', 'max:2048'],
            'settings.payments.qr_notes' => ['nullable', 'string', 'max:500'],
            'settings.payments.binance_enabled' => ['sometimes', 'boolean'],
            'settings.payments.binance_image' => ['nullable', 'string', 'max:2048'],
            'settings.payments.binance_pay_id' => ['nullable', 'string', 'max:120'],
            'settings.payments.binance_notes' => ['nullable', 'string', 'max:500'],
            'settings.payments.deposit_enabled' => ['sometimes', 'boolean'],
            'settings.payments.deposit_notes' => ['nullable', 'string', 'max:500'],
            'settings.payments.transfer_enabled' => ['sometimes', 'boolean'],
            'settings.payments.transfer_notes' => ['nullable', 'string', 'max:500'],
            'settings.payments.bank_name' => ['nullable', 'string', 'max:120'],
            'settings.payments.bank_account_holder' => ['nullable', 'string', 'max:160'],
            'settings.payments.bank_account_number' => ['nullable', 'string', 'max:80'],
            'settings.payments.bank_account_type' => ['nullable', 'string', 'in:ahorros,corriente'],
            'settings.payments.bank_document' => ['nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');
        if (! is_array($settings)) {
            return;
        }

        if (isset($settings['currency'])) {
            $settings['currency'] = Currencies::normalize((string) $settings['currency']);
        }

        if (! isset($settings['dropshipping']) || ! is_array($settings['dropshipping'])) {
            $this->merge(['settings' => $settings]);

            return;
        }

        $dropshipping = $settings['dropshipping'];
        if (isset($dropshipping['origin_country'])) {
            $code = strtoupper(trim((string) $dropshipping['origin_country']));
            $dropshipping['origin_country'] = $code === '' ? null : $code;
        }

        if (isset($dropshipping['zones']) && is_array($dropshipping['zones'])) {
            $dropshipping['zones'] = array_values(array_map(function ($zone) {
                if (! is_array($zone)) {
                    return $zone;
                }
                if (isset($zone['country'])) {
                    $zone['country'] = strtoupper(trim((string) $zone['country']));
                }

                return $zone;
            }, $dropshipping['zones']));
        }

        $settings['dropshipping'] = $dropshipping;
        $this->merge(['settings' => $settings]);
    }
}
