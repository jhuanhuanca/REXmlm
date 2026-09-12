<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Actions;

use App\Models\User;
use App\Modules\MLM\Actions\PromotePartnerToLeaderAction;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\NetworkStatus;
use RuntimeException;

class GrantComplimentarySubscriptionAction
{
    public const ID_PREFIX = 'comp_';

    public function __construct(
        private readonly PromotePartnerToLeaderAction $promotePartner,
    ) {}

    public function handle(User $user, Plan $plan): Subscription
    {
        $existing = $user->subscription('default');

        if ($existing?->valid() && ! str_starts_with((string) $existing->stripe_id, self::ID_PREFIX)) {
            throw new RuntimeException(
                'Este usuario ya tiene una suscripción de Paddle. No se sobrescribe.',
            );
        }

        $wasPartner = $user->hasRole(config('rexmlm.roles.partner'))
            && ! $user->hasRole(config('rexmlm.roles.leader'));

        if ($wasPartner || ! $user->ownedNetwork) {
            $this->promotePartner->handle($user);
            $user->refresh();
        }

        $user->ownedNetwork()?->update([
            'status' => NetworkStatus::Active,
        ]);

        $stripeId = self::ID_PREFIX.$user->id;

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->updateOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $user->id,
                'type' => 'default',
                'stripe_status' => 'active',
                'stripe_price' => $plan->paddlePriceId() ?: 'complimentary',
                'quantity' => 1,
                'plan_id' => $plan->id,
                'network_id' => $user->current_network_id ?: $user->ownedNetwork?->id,
                'ends_at' => null,
            ],
        );

        return $subscription;
    }

    public function revoke(User $user): ?Subscription
    {
        $subscription = $user->subscription('default');

        if ($subscription === null || ! str_starts_with((string) $subscription->stripe_id, self::ID_PREFIX)) {
            return null;
        }

        $subscription->forceFill([
            'stripe_status' => 'canceled',
            'ends_at' => now(),
        ])->save();

        return $subscription;
    }
}
