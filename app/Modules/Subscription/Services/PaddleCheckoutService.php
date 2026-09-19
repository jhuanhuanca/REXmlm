<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use App\Models\User;
use App\Modules\Subscription\Models\Plan;
use Illuminate\Validation\ValidationException;

class PaddleCheckoutService
{
    public function __construct(
        private readonly PaddleClient $paddle,
    ) {}

    /**
     * @param  array<string, scalar|null>  $custom
     */
    public function hostedCheckoutUrl(User $user, Plan $plan, array $custom = []): string
    {
        $priceId = $plan->paddlePriceId();

        if ($priceId === null) {
            throw ValidationException::withMessages([
                'plan_id' => ['Este plan no tiene un precio de Paddle (pri_…). Cárgalo en el panel admin.'],
            ]);
        }

        $customerId = $this->ensureCustomer($user);

        $payload = [
            'items' => [
                [
                    'price_id' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'customer_id' => $customerId,
            'currency_code' => strtoupper((string) ($plan->currency ?: 'USD')),
            'collection_mode' => 'automatic',
            'custom_data' => $this->customData(array_merge([
                'kind' => 'platform_plan',
                'intro' => 'dollar_1',
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
            ], $custom)),
        ];

        $discountId = $plan->paddleIntroDiscountId();
        if ($discountId === null) {
            throw ValidationException::withMessages([
                'plan_id' => ['Este plan no tiene el descuento de US$ 1 (dsc_…). En Paddle no uses trial gratis: el primer ciclo se cobra un dólar con ese descuento de un solo uso.'],
            ]);
        }

        $payload['discount_id'] = $discountId;

        $transaction = $this->paddle->createTransaction($payload);

        return $this->checkoutUrl($transaction, 'plan_id');
    }

    /**
     * @param  array<string, scalar|null>  $custom
     */
    public function oneTimeCheckoutUrl(User $user, string $description, float $amount, string $currency, array $custom = []): string
    {
        $cents = (string) (int) round($amount * 100);
        $customerId = $this->ensureCustomer($user);

        $transaction = $this->paddle->createTransaction([
            'items' => [
                [
                    'quantity' => 1,
                    'price' => [
                        'description' => $description,
                        'unit_price' => [
                            'amount' => $cents,
                            'currency_code' => strtoupper($currency),
                        ],
                        'product' => [
                            'name' => $description,
                            'tax_category' => 'standard',
                        ],
                    ],
                ],
            ],
            'customer_id' => $customerId,
            'currency_code' => strtoupper($currency),
            'collection_mode' => 'automatic',
            'custom_data' => $this->customData(array_merge([
                'kind' => 'one_time',
                'user_id' => (string) $user->id,
            ], $custom)),
        ]);

        return $this->checkoutUrl($transaction, 'catalog_company_id');
    }

    /**
     * @param  array<string, scalar|null>  $custom
     */
    public function recurringPriceCheckoutUrl(User $user, string $priceId, string $currency, array $custom = []): string
    {
        $customerId = $this->ensureCustomer($user);

        $transaction = $this->paddle->createTransaction([
            'items' => [
                [
                    'price_id' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'customer_id' => $customerId,
            'currency_code' => strtoupper($currency),
            'collection_mode' => 'automatic',
            'custom_data' => $this->customData(array_merge([
                'kind' => 'one_time',
                'user_id' => (string) $user->id,
            ], $custom)),
        ]);

        return $this->checkoutUrl($transaction, 'catalog_company_id');
    }

    private function ensureCustomer(User $user): string
    {
        if (filled($user->stripe_id) && str_starts_with((string) $user->stripe_id, 'ctm_')) {
            return (string) $user->stripe_id;
        }

        $customer = $this->paddle->createCustomer([
            'email' => $user->email,
            'name' => $user->name,
            'custom_data' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $id = (string) ($customer['id'] ?? '');

        if ($id === '') {
            throw ValidationException::withMessages([
                'plan_id' => ['Paddle no creó el cliente.'],
            ]);
        }

        $user->forceFill(['stripe_id' => $id])->save();

        return $id;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function checkoutUrl(array $transaction, string $errorKey): string
    {
        $url = data_get($transaction, 'checkout.url');

        if (! is_string($url) || $url === '') {
            throw ValidationException::withMessages([
                $errorKey => ['Paddle no devolvió la URL de pago.'],
            ]);
        }

        return $url;
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, string>
     */
    private function customData(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }
}
