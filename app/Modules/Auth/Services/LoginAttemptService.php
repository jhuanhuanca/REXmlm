<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class LoginAttemptService
{
    public function assertCanAttempt(?User $user): void
    {
        if ($user === null) {
            return;
        }

        if ($user->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta está suspendida.'],
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => ['Cuenta bloqueada temporalmente. Intenta más tarde.'],
            ]);
        }
    }

    public function recordFailure(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;
        $payload = ['failed_login_attempts' => $attempts];

        if ($attempts >= (int) config('rexmlm.max_login_attempts')) {
            $payload['locked_until'] = now()->addMinutes((int) config('rexmlm.lockout_minutes'));
        }

        $user->forceFill($payload)->save();
    }

    public function recordSuccess(User $user): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }
}
