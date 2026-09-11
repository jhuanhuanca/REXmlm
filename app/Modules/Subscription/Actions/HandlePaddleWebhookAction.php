<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Actions;

use App\Models\User;
use App\Modules\Commission\Actions\AccrueReferralSubscriptionCommission;
use App\Modules\MLM\Actions\PromotePartnerToLeaderAction;
use App\Modules\Organization\Actions\AddSecondaryCompanyAction;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\NetworkStatus;
use Illuminate\Support\Facades\Log;

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
            $this->completeSecondaryCompany($custom);

            return;
        }

        if ($kind === 'platform_plan' || filled($data['subscription_id'] ?? null)) {
            $this->activateFromTransaction($data, $custom);
        }
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function completeSecondaryCompany(array $custom): void
    {
        $userId = (int) ($custom['user_id'] ?? 0);
        $companyId = (int) ($custom['catalog_company_id'] ?? 0);
        $user = User::query()->find($userId);

        if ($user === null || $companyId < 1) {
            Log::warning('Webhook Paddle: empresa secundaria sin usuario o empresa.', $custom);

            return;
        }

        if ($user->hasCompanyMembership($companyId)) {
            return;
        }

        $this->addSecondaryCompany->handle(
            $user,
            $companyId,
            isset($custom['catalog_rank_id']) ? (int) $custom['catalog_rank_id'] : null,
            isset($custom['catalog_rank_name']) ? (string) $custom['catalog_rank_name'] : null,
            true,
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

        $paddleSubId = (string) ($data['subscription_id'] ?? $data['id'] ?? '');
        $priceId = (string) (data_get($data, 'items.0.price.id') ?: $plan->paddlePriceId() ?: '');

        $this->upsertSubscription($user, $plan, $paddleSubId, $priceId, 'active');
        $this->grantLeaderAccess($user, $plan);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function onSubscription(array $data): void
    {
        $custom = is_array($data['custom_data'] ?? null) ? $data['custom_data'] : [];
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

        $subscription = $this->upsertSubscription($user, $plan, $paddleSubId, $priceId, $status);

        if (in_array($status, ['canceled', 'past_due', 'paused'], true)) {
            $subscription->forceFill([
                'ends_at' => $status === 'canceled' ? now() : $subscription->ends_at,
            ])->save();

            return;
        }

        $this->grantLeaderAccess($user, $plan);
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

        $subscription = $user->subscription('default');
        $this->accrueCommission->handle($user, $plan, $subscription);
    }

    private function upsertSubscription(User $user, Plan $plan, string $paddleId, string $priceId, string $status): Subscription
    {
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
            ],
        );

        return $subscription;
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
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'paused' => 'paused',
            'canceled', 'cancelled' => 'canceled',
            default => 'active',
        };
    }
}
