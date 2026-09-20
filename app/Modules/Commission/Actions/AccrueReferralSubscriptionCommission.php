<?php

declare(strict_types=1);

namespace App\Modules\Commission\Actions;

use App\Models\User;
use App\Modules\Commission\Jobs\NotifyReferrerOfCommission;
use App\Modules\Commission\Models\Commission;
use App\Modules\MLM\Models\Referral;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\CommissionStatus;
use App\Shared\Enums\ReferralStatus;
use App\Shared\Support\Currencies;

class AccrueReferralSubscriptionCommission
{
    public function handle(User $referred, Plan $plan, ?Subscription $subscription = null): ?Commission
    {
        $referral = Referral::query()
            ->with('referrer:id,current_network_id')
            ->where('referred_id', $referred->id)
            ->whereIn('status', [ReferralStatus::Active, ReferralStatus::Independent])
            ->first();

        $referrerId = $referral?->referrer_id ?: $referred->sponsor_user_id;

        if (! $referrerId || (int) $referrerId === (int) $referred->id) {
            return null;
        }

        $existing = Commission::query()
            ->where('referred_id', $referred->id)
            ->whereIn('source_type', ['subscription_invoice', 'partner_upgrade', 'referral_subscription'])
            ->whereNotIn('status', [CommissionStatus::Cancelled, CommissionStatus::Reversed])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $percentage = (float) config('rexmlm.referral_subscription_commission', 10);
        $amount = round(((float) $plan->price) * ($percentage / 100), 2);

        if ($amount <= 0) {
            return null;
        }

        $commission = Commission::query()->firstOrCreate(
            [
                'source_type' => $subscription ? 'subscription_invoice' : 'partner_upgrade',
                'source_id' => 'lifetime:'.$referred->id,
                'referrer_id' => $referrerId,
            ],
            [
                'referred_id' => $referred->id,
                'network_id' => $referral?->referrer?->current_network_id,
                'subscription_id' => $subscription?->id,
                'amount' => $amount,
                'percentage' => $percentage,
                'currency' => Currencies::COMMISSION,
                'status' => CommissionStatus::Pending,
                'meta' => [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'plan_price' => (float) $plan->price,
                    'plan_currency' => $plan->currency ?: Currencies::COMMISSION,
                    'reason' => 'referred_became_leader',
                    'basis' => 'list_price_once',
                ],
            ],
        );

        if ($commission->wasRecentlyCreated) {
            NotifyReferrerOfCommission::dispatch($commission);
        }

        return $commission;
    }
}
