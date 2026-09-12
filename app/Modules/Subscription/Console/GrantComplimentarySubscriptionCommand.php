<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Console;

use App\Models\User;
use App\Modules\Subscription\Actions\GrantComplimentarySubscriptionAction;
use App\Modules\Subscription\Models\Plan;
use Illuminate\Console\Command;
use RuntimeException;

class GrantComplimentarySubscriptionCommand extends Command
{
    protected $signature = 'rexmlm:grant-complimentary
                            {email : Correo del usuario}
                            {--plan= : ID o slug del plan (por defecto el primer plan activo)}
                            {--revoke : Quita solo la suscripción de cortesía (no toca Paddle)}';

    protected $description = 'Otorga o revoca una suscripción SaaS de cortesía (sin Paddle ni comisión)';

    public function handle(GrantComplimentarySubscriptionAction $grant): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error('No hay un usuario con ese correo.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $revoked = $grant->revoke($user);

            if ($revoked === null) {
                $this->warn('Ese usuario no tiene una suscripción de cortesía.');

                return self::FAILURE;
            }

            $this->info("Cortesía revocada para {$user->email}.");

            return self::SUCCESS;
        }

        $plan = $this->resolvePlan();

        if ($plan === null) {
            $this->error('No hay un plan activo. Crea uno en el admin o pasa --plan=.');

            return self::FAILURE;
        }

        try {
            $subscription = $grant->handle($user, $plan);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Cortesía activa para {$user->email} (plan {$plan->name}, id {$subscription->stripe_id}).");
        $this->comment('Paddle no cambia. Esta fila no genera comisión de referido.');

        return self::SUCCESS;
    }

    private function resolvePlan(): ?Plan
    {
        $raw = trim((string) $this->option('plan'));

        if ($raw === '') {
            return Plan::query()->active()->orderBy('id')->first();
        }

        if (ctype_digit($raw)) {
            return Plan::query()->active()->find((int) $raw);
        }

        return Plan::query()->active()->where('slug', $raw)->first();
    }
}
