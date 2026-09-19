<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Actions\CompleteGoogleAuthAction;
use App\Modules\Auth\Actions\RegisterUserAction;
use App\Modules\Auth\Http\Requests\GoogleAuthRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Requests\UpdatePasswordRequest;
use App\Modules\Auth\Http\Resources\AuthUserResource;
use App\Modules\Auth\Jobs\SendWelcomeEmail;
use App\Modules\Auth\Services\GoogleIdentityService;
use App\Modules\Auth\Services\LoginAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\TransientToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->handle($request->validated());
        SendWelcomeEmail::dispatch($user);
        $token = $user->createAuthToken();

        return response()->json([
            'user' => new AuthUserResource($user),
            'token' => $token,
        ], 201);
    }

    public function google(
        GoogleAuthRequest $request,
        GoogleIdentityService $google,
        CompleteGoogleAuthAction $action,
        LoginAttemptService $attempts,
    ): JsonResponse {
        $identity = $google->userFromIdToken((string) $request->validated('id_token'));
        $existing = User::query()->where('google_id', $identity['sub'])->first()
            ?? User::query()->where('email', $identity['email'])->first();

        $attempts->assertCanAttempt($existing);

        $result = $action->handle($identity, $request->validated());
        $user = $result['user'];

        if ($result['created']) {
            SendWelcomeEmail::dispatch($user);
        }

        return $this->completeLogin($user, $attempts, $result['created'] ? 201 : 200);
    }

    public function login(LoginRequest $request, LoginAttemptService $attempts): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        $attempts->assertCanAttempt($user);

        if ($user === null || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            $attempts->recordFailure($user);

            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        return $this->completeLogin($user, $attempts);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && ! $token instanceof TransientToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        return response()->json([
            'message' => 'Sesión cerrada',
        ]);
    }

    public function me(Request $request): AuthUserResource
    {
        $request->user()->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);
        app(\App\Modules\Organization\Actions\EnsurePrimaryCompanyMembership::class)->handle($request->user());
        $request->user()->load('companyMemberships');

        return new AuthUserResource($request->user());
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $needsCurrent = ! filled($user->google_id);

        if ($needsCurrent && ! Hash::check((string) $request->validated('current_password'), $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->forceFill([
            'password' => $request->validated('password'),
        ])->save();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    private function completeLogin(User $user, LoginAttemptService $attempts, int $status = 200): JsonResponse
    {
        Auth::guard('web')->logout();

        $user->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);
        $pendingMinutes = (int) config('rexmlm.two_factor.pending_minutes', 15);

        if ($user->mustEnrollTwoFactor()) {
            $token = $user->createAuthToken('two_factor_setup', ['two-factor:setup'], $pendingMinutes);

            return response()->json([
                'user' => new AuthUserResource($user),
                'token' => $token,
                'two_factor_status' => 'setup',
            ], $status);
        }

        if ($user->hasTwoFactorEnabled()) {
            $token = $user->createAuthToken('two_factor_challenge', ['two-factor:challenge'], $pendingMinutes);

            return response()->json([
                'user' => new AuthUserResource($user),
                'token' => $token,
                'two_factor_status' => 'challenge',
            ], $status);
        }

        $attempts->recordSuccess($user);

        return response()->json([
            'user' => new AuthUserResource($user),
            'token' => $user->createAuthToken(),
            'two_factor_status' => 'ok',
        ], $status);
    }
}
