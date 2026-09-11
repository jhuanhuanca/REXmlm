<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Resources\AuthUserResource;
use App\Modules\Auth\Services\LoginAttemptService;
use App\Modules\Auth\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\TransientToken;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, setup: true);

        $payload = $twoFactor->beginSetup($user);

        return response()->json($payload);
    }

    public function confirm(
        Request $request,
        TwoFactorService $twoFactor,
        LoginAttemptService $attempts,
    ): JsonResponse {
        $user = $request->user();
        $this->assertCanManage($user, setup: true);

        $code = $this->validatedCode($request);
        $recovery = $twoFactor->confirmSetup($user, $code);

        if ($recovery === []) {
            $attempts->recordFailure($user);

            throw ValidationException::withMessages([
                'code' => ['Código inválido.'],
            ]);
        }

        $attempts->recordSuccess($user);

        return $this->finishWithToken($user, [
            'recovery_codes' => $recovery,
        ]);
    }

    public function challenge(
        Request $request,
        TwoFactorService $twoFactor,
        LoginAttemptService $attempts,
    ): JsonResponse {
        $user = $request->user();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => ['No hay un desafío de dos pasos activo.'],
            ]);
        }

        $code = $this->validatedCode($request, recovery: true);

        if (! $twoFactor->verifyLogin($user, $code)) {
            $attempts->recordFailure($user);

            throw ValidationException::withMessages([
                'code' => ['Código inválido.'],
            ]);
        }

        $attempts->recordSuccess($user);

        return $this->finishWithToken($user);
    }

    public function disable(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => ['La verificación en dos pasos no está activa.'],
            ]);
        }

        if ($user->hasRole('admin')) {
            return response()->json([
                'message' => 'Los administradores no pueden desactivar la verificación en dos pasos.',
            ], 403);
        }

        $code = $this->validatedCode($request, recovery: true);

        if (! $twoFactor->verifyLogin($user, $code)) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido.'],
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json(['message' => 'Verificación en dos pasos desactivada.']);
    }

    private function assertCanManage(?User $user, bool $setup): void
    {
        if ($user === null) {
            abort(401);
        }

        if ($setup && $user->hasTwoFactorEnabled() && ! $this->tokenIsPending($user, 'two-factor:setup')) {
            abort(response()->json([
                'message' => 'La verificación en dos pasos ya está activa.',
            ], 422));
        }
    }

    private function tokenIsPending(User $user, string $ability): bool
    {
        $token = $user->currentAccessToken();

        return $token
            && ! $token instanceof TransientToken
            && method_exists($token, 'can')
            && $token->can($ability)
            && ! $token->can('*');
    }

    private function validatedCode(Request $request, bool $recovery = false): string
    {
        $rules = $recovery
            ? ['required', 'string', 'max:32']
            : ['required', 'string', 'size:6'];

        $validated = $request->validate([
            'code' => $rules,
        ]);

        return (string) $validated['code'];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finishWithToken(User $user, array $extra = []): JsonResponse
    {
        $user->tokens()
            ->whereIn('name', ['two_factor_setup', 'two_factor_challenge'])
            ->delete();

        $user->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);

        Auth::guard('web')->logout();
        if (request()->hasSession()) {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        return response()->json([
            'user' => new AuthUserResource($user),
            'token' => $user->createAuthToken(),
            'two_factor_status' => 'ok',
            ...$extra,
        ]);
    }
}
