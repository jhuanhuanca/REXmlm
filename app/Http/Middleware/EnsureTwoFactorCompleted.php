<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        [$user, $token] = $this->resolveUserAndToken($request);

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $token instanceof PersonalAccessToken || $token instanceof MockInterface) {
            return $next($request);
        }

        if ($this->isPendingAbility($token, 'two-factor:setup')) {
            if ($this->isTwoFactorRoute($request)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Debes activar la verificación en dos pasos.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        if ($this->isPendingAbility($token, 'two-factor:challenge')) {
            if ($this->isTwoFactorRoute($request)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Ingresa el código de autenticación.',
                'code' => 'two_factor_required',
            ], 403);
        }

        if ($user->mustEnrollTwoFactor()) {
            return response()->json([
                'message' => 'Los administradores deben activar la verificación en dos pasos.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }

    /**
     * @return array{0: User|null, 1: mixed}
     */
    private function resolveUserAndToken(Request $request): array
    {
        $plain = $request->bearerToken();

        if (is_string($plain) && $plain !== '') {
            $token = PersonalAccessToken::findToken($plain);
            $user = $token?->tokenable;

            if ($user instanceof User && $token instanceof PersonalAccessToken) {
                $fresh = $user->fresh();
                $user = $fresh instanceof User ? $fresh : $user;
                $user->withAccessToken($token);

                return [$user, $token];
            }
        }

        $user = $request->user();

        return [
            $user instanceof User ? $user : null,
            $user instanceof User ? $user->currentAccessToken() : null,
        ];
    }

    private function isPendingAbility(mixed $token, string $ability): bool
    {
        return method_exists($token, 'can')
            && $token->can($ability)
            && ! $token->can('*');
    }

    private function isTwoFactorRoute(Request $request): bool
    {
        return $request->is('api/v1/auth/two-factor/*')
            || $request->is('api/v1/auth/logout');
    }
}
