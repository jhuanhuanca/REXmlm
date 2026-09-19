<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Console;

use App\Mail\SubscriptionPastDueMail;
use App\Mail\SubscriptionRenewalReminderMail;
use App\Modules\Subscription\Actions\GrantComplimentarySubscriptionAction;
use App\Modules\Subscription\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class NotifySubscriptionLifecycleCommand extends Command
{
    protected $signature = 'rexmlm:notify-subscription-lifecycle';

    protected $description = 'Avisa 2 días antes del cobro y cuando la suscripción queda impaga';

    public function handle(): int
    {
        $reminded = $this->remindRenewals();
        $pastDue = $this->notifyPastDue();

        $this->info("Recordatorios de renovación: {$reminded}. Impagos avisados: {$pastDue}.");

        return self::SUCCESS;
    }

    private function remindRenewals(): int
    {
        $from = now()->startOfDay();
        $to = now()->addDays(2)->endOfDay();
        $sent = 0;

        Subscription::query()
            ->with(['user', 'plan'])
            ->whereIn('stripe_status', ['active', 'trialing'])
            ->whereNull('ends_at')
            ->whereNotNull('next_billed_at')
            ->whereBetween('next_billed_at', [$from, $to])
            ->chunkById(100, function ($rows) use (&$sent) {
                foreach ($rows as $subscription) {
                    if (str_starts_with((string) $subscription->stripe_id, GrantComplimentarySubscriptionAction::ID_PREFIX)) {
                        continue;
                    }

                    $user = $subscription->user;
                    $plan = $subscription->plan;
                    if ($user === null || $plan === null || ! $user->email) {
                        continue;
                    }

                    $next = $subscription->next_billed_at instanceof \Carbon\CarbonInterface
                        ? $subscription->next_billed_at
                        : \Illuminate\Support\Carbon::parse((string) $subscription->next_billed_at);
                    $date = $next->timezone(config('app.timezone'))->toDateString();
                    if ((string) $subscription->renewal_reminder_for === $date) {
                        continue;
                    }
                    Mail::to($user->email)->send(new SubscriptionRenewalReminderMail(
                        $user,
                        $subscription,
                        (float) $plan->price,
                        $next->timezone(config('app.timezone'))->isoFormat('D [de] MMMM YYYY'),
                    ));
                    $subscription->forceFill(['renewal_reminder_for' => $date])->save();
                    $sent++;
                }
            });

        return $sent;
    }

    private function notifyPastDue(): int
    {
        $sent = 0;

        Subscription::query()
            ->with(['user', 'plan'])
            ->where('stripe_status', 'past_due')
            ->whereNull('past_due_notified_at')
            ->chunkById(100, function ($rows) use (&$sent) {
                foreach ($rows as $subscription) {
                    $user = $subscription->user;
                    if ($user === null || ! $user->email) {
                        continue;
                    }

                    Mail::to($user->email)->send(new SubscriptionPastDueMail($user, $subscription));
                    $subscription->forceFill(['past_due_notified_at' => now()])->save();
                    $sent++;
                }
            });

        return $sent;
    }
}
