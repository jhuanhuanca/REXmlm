<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Actions;

use App\Models\User;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Services\PaddleClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ManageLeaderBillingAction
{
    public function __construct(
        private readonly PaddleClient $paddle,
    ) {}

    /**
     * @return list<array{id: string, billed_at: ?string, amount: float, currency: string, status: string, description: string}>
     */
    public function invoices(User $user): array
    {
        $subscription = $user->subscription('default');
        $customerId = (string) ($user->stripe_id ?? '');

        if (
            $subscription === null
            || str_starts_with((string) $subscription->stripe_id, GrantComplimentarySubscriptionAction::ID_PREFIX)
            || ! str_starts_with($customerId, 'ctm_')
            || ! $this->paddle->configured()
            || (bool) config('billing.offline')
        ) {
            return [];
        }

        try {
            $rows = $this->paddle->list('/transactions', [
                'customer_id' => $customerId,
                'per_page' => 30,
            ]);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cents = data_get($row, 'details.totals.grand_total')
                ?? data_get($row, 'details.totals.total');
            $currency = strtoupper((string) (data_get($row, 'currency_code') ?: 'USD'));
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'billed_at' => isset($row['billed_at']) ? (string) $row['billed_at'] : (isset($row['created_at']) ? (string) $row['created_at'] : null),
                'amount' => is_numeric($cents) ? round(((int) $cents) / 100, 2) : 0.0,
                'currency' => $currency,
                'status' => (string) ($row['status'] ?? ''),
                'description' => (string) (data_get($row, 'items.0.price.description')
                    ?: data_get($row, 'items.0.price.name')
                    ?: $subscription->plan?->name
                    ?: 'Suscripción'),
            ];
        }

        return $out;
    }

    public function cancelAtPeriodEnd(User $user): Subscription
    {
        $subscription = $user->subscription('default');

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan_id' => ['No hay una suscripción activa para cancelar.'],
            ]);
        }

        if ($subscription->ends_at !== null) {
            return $subscription;
        }

        $endsAt = $subscription->next_billed_at
            ? Carbon::parse((string) $subscription->next_billed_at)
            : now()->addMonth();

        $paddleId = (string) $subscription->stripe_id;
        $live = $this->paddle->configured() && ! (bool) config('billing.offline')
            && str_starts_with($paddleId, 'sub_');

        if ($live) {
            $this->paddle->post('/subscriptions/'.$paddleId.'/cancel', [
                'effective_from' => 'next_billing_period',
            ]);
        }

        $subscription->forceFill([
            'ends_at' => $endsAt,
        ])->save();

        return $subscription->fresh() ?? $subscription;
    }

    public function changePlan(User $user, Plan $plan): Subscription
    {
        $subscription = $user->subscription('default');

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan_id' => ['Primero activa un plan. El cambio de paquete es para una suscripción ya cobrada.'],
            ]);
        }

        if ((int) $subscription->plan_id === (int) $plan->id) {
            throw ValidationException::withMessages([
                'plan_id' => ['Ese ya es tu plan actual.'],
            ]);
        }

        $current = $subscription->plan;
        $upgrade = $current === null || (float) $plan->price >= (float) $current->price;
        $paddleId = (string) $subscription->stripe_id;
        $priceId = $plan->paddlePriceId();
        $live = $this->paddle->configured() && ! (bool) config('billing.offline')
            && str_starts_with($paddleId, 'sub_');

        if ($live) {
            if ($priceId === null) {
                throw ValidationException::withMessages([
                    'plan_id' => ['Este plan no tiene un precio de Paddle (pri_…).'],
                ]);
            }

            $this->paddle->patch('/subscriptions/'.$paddleId, [
                'items' => [
                    [
                        'price_id' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'proration_billing_mode' => $upgrade ? 'prorated_immediately' : 'do_not_bill',
            ]);
        }

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'stripe_price' => $priceId ?: $subscription->stripe_price,
            'ends_at' => $upgrade ? null : $subscription->ends_at,
        ])->save();

        return $subscription->fresh(['plan']) ?? $subscription;
    }
}
