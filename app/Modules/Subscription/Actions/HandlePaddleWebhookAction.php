<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Actions;

use App\Mail\SubscriptionPastDueMail;
use App\Models\User;
use App\Modules\Commission\Actions\AccrueReferralSubscriptionCommission;
use App\Modules\MLM\Actions\PromotePartnerToLeaderAction;
use App\Modules\Organization\Actions\AddSecondaryCompanyAction;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\NetworkStatus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HandlePaddleWebhookAction
{
    public function __construct(
        private readonly PromotePartnerToLeaderAction $promotePartner,
        private readonly AccrueReferralSubscriptionCommission $accrueCommission,
        private readonly AddSecondaryCompanyAction $addSecondaryCompany,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $type = (string) ($event['event_type'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        match ($type) {
            'transaction.completed', 'transaction.paid' => $this->onTransactionPaid($data),
            'subscription.activated', 'subscription.created', 'subscription.updated' => $this->onSubscription($data),
            'subscription.canceled', 'subscription.past_due' => $this->onSubscription($data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onTransactionPaid(array $data): void
    {
        $custom = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];
        $kind = (string) ($custom['kind'] ?? '');

        if ($kind === 'secondary_company') {
            $this->completeSecondaryCompany($custom, $data);

            return;
        }

        if ($kind === 'platform_plan' || filled($data['subscription_id'] ?? null)) {
            $this->activateFromTransaction($data, $custom);
        }
    }

    /**
     * @param  array<string, mixed>  $custom
     * @param  array<string, mixed>  $data
     */
    private function completeSecondaryCompany(array $custom, array $data): void
    {
        $userId = (int) ($custom['user_id'] ?? 0);
        $companyId = (int) ($custom['catalog_company_id'] ?? 0);
        $user = User::query()->find($userId);

        if ($user === null || $companyId < 1) {
            Log::warning('Webhook Paddle: empresa secundaria sin usuario o empresa.', $custom);

            return;
        }

        if ($user->hasCompanyMembership($companyId)) {
            $membership = $user->membershipForCompany($companyId);
            $membership?->forceFill([
                'billing_status' => 'active',
                'paddle_subscription_id' => (string) ($data['subscription_id'] ?? $membership->paddle_subscription_id),
            ])->save();

            return;
        }

        $this->addSecondaryCompany->handle(
            $user,
            $companyId,
            isset($custom['catalog_rank_id']) ? (int) $custom['catalog_rank_id'] : null,
            isset($custom['catalog_rank_name']) ? (string) $custom['catalog_rank_name'] : null,
            true,
            (string) ($data['subscription_id'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $custom
     */
    private function activateFromTransaction(array $data, array $custom): void
    {
        $userId = (int) ($custom['user_id'] ?? 0);
        $planId = (int) ($custom['plan_id'] ?? 0);
        $user = User::query()->find($userId);
        $plan = $planId > 0 ? Plan::query()->find($planId) : null;

        if ($user === null || $plan === null) {
            Log::warning('Webhook Paddle: transacción de plan sin usuario o plan.', $custom);

            return;
        }

        $cents = $this->transactionTotalCents($data);
        if ($cents === 0) {
            Log::warning('Webhook Paddle: cobro a US$ 0 ignorado. No hay mes gratis; el arranque es US$ 1.', [
                'user_id' => $userId,
                'plan_id' => $planId,
            ]);

            return;
        }

        $paddleSubId = (string) ($data['subscription_id'] ?? $data['id'] ?? '');
        $priceId = (string) (data_get($data, 'items.0.price.id') ?: $plan->paddlePriceId() ?: '');

        $subscription = $this->upsertSubscription($user, $plan, $paddleSubId, $priceId, 'active', $data);
        $this->grantLeaderAccess($user, $plan);

        if (! $this->isIntroCycle($data, $plan)) {
            $this->accrueCommission->handle($user, $plan, $subscription);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onSubscription(array $data): void
    {
        $custom = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];

        if ((string) ($custom['kind'] ?? '') === 'secondary_company') {
            $this->syncSecondaryCompanySubscription($data, $custom);

            return;
        }

        $user = $this->userFromPaddle($data, $custom);

        if ($user === null) {
            return;
        }

        $planId = (int) ($custom['plan_id'] ?? 0);
        $plan = $planId > 0 ? Plan::query()->find($planId) : $user->subscription('default')?->plan;
        $paddleSubId = (string) ($data['id'] ?? '');
        $status = $this->mapStatus((string) ($data['status'] ?? 'active'));
        $priceId = (string) (data_get($data, 'items.0.price.id') ?: $plan?->paddlePriceId() ?: '');

        if ($plan === null || $paddleSubId === '') {
            return;
        }

        $subscription = $this->upsertSubscription($user, $plan, $paddleSubId, $priceId, $status, $data);

        if (in_array($status, ['canceled', 'past_due', 'paused'], true)) {
            $subscription->forceFill([
                'ends_at' => $status === 'canceled' ? now() : $subscription->ends_at,
            ])->save();

            if ($status === 'past_due') {
                $this->notifyPastDue($user, $subscription);
            }

            return;
        }

        $subscription->forceFill(['past_due_notified_at' => null])->save();
        $this->grantLeaderAccess($user, $plan);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $custom
     */
    private function syncSecondaryCompanySubscription(array $data, array $custom): void
    {
        $companyId = (int) ($custom['catalog_company_id'] ?? 0);
        $user = $this->userFromPaddle($data, $custom);
        $status = $this->mapStatus((string) ($data['status'] ?? 'active'));

        if ($user === null || $companyId < 1) {
            return;
        }

        $membership = $user->membershipForCompany($companyId);

        if ($membership === null || $membership->is_primary) {
            return;
        }

        $membership->forceFill([
            'billing_status' => $status,
            'paddle_subscription_id' => (string) ($data['id'] ?? $membership->paddle_subscription_id),
        ])->save();

        if (in_array($status, ['canceled', 'past_due', 'paused'], true)
            && (int) $user->active_catalog_company_id === $companyId
            && $user->catalog_company_id
        ) {
            $user->forceFill(['active_catalog_company_id' => (int) $user->catalog_company_id])->save();
        }
    }

    private function grantLeaderAccess(User $user, Plan $plan): void
    {
        $wasPartner = $user->hasRole(config('rexmlm.roles.partner'))
            && ! $user->hasRole(config('rexmlm.roles.leader'));

        if ($wasPartner || ! $user->ownedNetwork) {
            $this->promotePartner->handle($user);
            $user->refresh();
        }

        $user->ownedNetwork()?->update([
            'status' => NetworkStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertSubscription(
        User $user,
        Plan $plan,
        string $paddleId,
        string $priceId,
        string $status,
        array $data = [],
    ): Subscription {
        $nextBilled = $this->timestampFromPaddle(
            data_get($data, 'next_billed_at') ?: data_get($data, 'current_billing_period.ends_at')
        );

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->updateOrCreate(
            ['stripe_id' => $paddleId],
            [
                'user_id' => $user->id,
                'type' => 'default',
                'stripe_status' => $status,
                'stripe_price' => $priceId !== '' ? $priceId : $plan->paddlePriceId(),
                'quantity' => 1,
                'plan_id' => $plan->id,
                'network_id' => $user->current_network_id ?: $user->ownedNetwork?->id,
                'ends_at' => $status === 'canceled' ? now() : null,
                'trial_ends_at' => null,
                'next_billed_at' => $nextBilled,
            ],
        );

        return $subscription;
    }

    private function notifyPastDue(User $user, Subscription $subscription): void
    {
        if ($subscription->past_due_notified_at !== null) {
            return;
        }

        Mail::to($user->email)->send(new SubscriptionPastDueMail($user, $subscription));
        $subscription->forceFill(['past_due_notified_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isIntroCycle(array $data, Plan $plan): bool
    {
        $cents = $this->transactionTotalCents($data);

        if ($cents === null) {
            return false;
        }

        $listHalf = (int) round(((float) $plan->price) * 50);

        return $cents < max(200, $listHalf);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transactionTotalCents(array $data): ?int
    {
        $raw = data_get($data, 'details.totals.grand_total')
            ?? data_get($data, 'details.totals.total')
            ?? data_get($data, 'details.totals.subtotal')
            ?? data_get($data, 'items.0.price.unit_price.amount');

        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    private function timestampFromPaddle(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $custom
     */
    private function userFromPaddle(array $data, array $custom): ?User
    {
        $userId = (int) ($custom['user_id'] ?? 0);
        if ($userId > 0) {
            return User::query()->find($userId);
        }

        $customerId = (string) ($data['customer_id'] ?? '');
        if ($customerId === '') {
            return null;
        }

        return User::query()->where('stripe_id', $customerId)->first();
    }

    private function mapStatus(string $paddleStatus): string
    {
        return match ($paddleStatus) {
            'active', 'trialing' => 'active',
            'past_due' => 'past_due',
            'paused' => 'paused',
            'canceled', 'cancelled' => 'canceled',
            default => 'active',
        };
    }
}
