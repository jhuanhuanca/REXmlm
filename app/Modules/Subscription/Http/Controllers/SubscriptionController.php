<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Resources\AuthUserResource;
use App\Modules\Commission\Actions\AccrueReferralSubscriptionCommission;
use App\Modules\MLM\Actions\PromotePartnerToLeaderAction;
use App\Modules\Subscription\Actions\GrantComplimentarySubscriptionAction;
use App\Modules\Subscription\Actions\HandlePaddleWebhookAction;
use App\Modules\Subscription\Actions\ManageLeaderBillingAction;
use App\Modules\Subscription\Http\Requests\StoreSubscriptionRequest;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\Subscription;
use App\Modules\Subscription\Services\PaddleCheckoutService;
use App\Modules\Subscription\Services\PaddleClient;
use App\Modules\Subscription\Services\PaddleSignatureVerifier;
use App\Modules\Subscription\Services\PlanEntitlements;
use App\Shared\Enums\NetworkStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    public function overlay(): JsonResponse
    {
        $token = $this->paddleClientToken();

        return response()->json([
            'client_token' => $token !== '' ? $token : null,
            'sandbox' => (bool) config('services.paddle.sandbox'),
        ]);
    }

    private function paddleClientToken(): string
    {
        $candidates = [
            config('services.paddle.client_token'),
            $_ENV['PADDLE_CLIENT_TOKEN'] ?? null,
            $_SERVER['PADDLE_CLIENT_TOKEN'] ?? null,
            getenv('PADDLE_CLIENT_TOKEN') ?: null,
        ];

        foreach ($candidates as $value) {
            if (! is_string($value)) {
                continue;
            }
            $token = trim($value, " \t\n\r\0\x0B\"'");
            if ($token !== '' && (str_starts_with($token, 'live_') || str_starts_with($token, 'test_'))) {
                return $token;
            }
        }

        return '';
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->subscription('default');
        $plan = $subscription?->plan;

        return response()->json([
            'subscription' => $subscription,
            'billing' => [
                'has_paid_access' => $user->hasPaidPlatformAccess(),
                'status' => $subscription?->stripe_status,
                'next_billed_at' => $subscription?->next_billed_at,
                'ends_at' => $subscription?->ends_at,
                'complimentary' => PlanEntitlements::isComplimentary($user),
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'price' => (float) $plan->price,
                    'intro_price' => $plan->introPrice(),
                    'interval' => $plan->interval,
                    'currency' => $plan->currency,
                ] : null,
                'entitlements' => PlanEntitlements::forUser($user),
            ],
        ]);
    }

    public function invoices(Request $request, ManageLeaderBillingAction $billing): JsonResponse
    {
        return response()->json([
            'data' => $billing->invoices($request->user()),
        ]);
    }

    public function cancel(Request $request, ManageLeaderBillingAction $billing): JsonResponse
    {
        $subscription = $billing->cancelAtPeriodEnd($request->user());

        return response()->json([
            'message' => 'La suscripción se cancelará al final del periodo ya pagado. Hasta entonces el panel sigue activo.',
            'subscription' => $subscription,
            'ends_at' => $subscription->ends_at,
        ]);
    }

    public function store(
        StoreSubscriptionRequest $request,
        PromotePartnerToLeaderAction $promotePartner,
        AccrueReferralSubscriptionCommission $accrueCommission,
        PaddleClient $paddle,
        PaddleCheckoutService $checkout,
        ManageLeaderBillingAction $billing,
    ): JsonResponse {
        $plan = Plan::query()->active()->findOrFail($request->integer('plan_id'));
        $user = $request->user();
        $wasPartner = $user->hasRole(config('rexmlm.roles.partner'))
            && ! $user->hasRole(config('rexmlm.roles.leader'));
        $offline = (bool) config('billing.offline');
        $existing = $user->subscription('default');
        $existingId = (string) ($existing?->stripe_id ?? '');
        $paddleSub = $existing !== null && str_starts_with($existingId, 'sub_');

        if ($paddle->configured() && ! $offline) {
            if ($paddleSub) {
                $subscription = $billing->changePlan($user, $plan);
                $user->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);

                return response()->json([
                    'upgraded' => true,
                    'offline' => false,
                    'user' => new AuthUserResource($user),
                    'subscription' => $subscription,
                ]);
            }

            if ($existing !== null && str_starts_with($existingId, 'local_')) {
                $existing->delete();
            }

            $url = $checkout->hostedCheckoutUrl($user, $plan);

            return response()->json([
                'checkout_url' => $url,
                'promoted' => false,
                'offline' => false,
            ]);
        }

        if (! $offline) {
            throw ValidationException::withMessages([
                'plan_id' => ['El cobro con Paddle no está configurado. No se puede activar el plan en este entorno.'],
            ]);
        }

        if ($wasPartner) {
            $promotePartner->handle($user);
            $user->refresh();
        }

        $user->ownedNetwork()?->update([
            'status' => NetworkStatus::Active,
        ]);

        if ($wasPartner) {
            $accrueCommission->handle($user, $plan);
        }

        if ($existing && ! str_starts_with((string) $existing->stripe_id, GrantComplimentarySubscriptionAction::ID_PREFIX)) {
            $billing->changePlan($user, $plan);
        } elseif ($existing === null) {
            Subscription::query()->create([
                'user_id' => $user->id,
                'type' => 'default',
                'stripe_id' => 'local_'.$user->id,
                'stripe_status' => 'active',
                'stripe_price' => $plan->paddlePriceId() ?: 'local',
                'quantity' => 1,
                'plan_id' => $plan->id,
                'network_id' => $user->current_network_id ?: $user->ownedNetwork?->id,
                'next_billed_at' => now()->addMonth(),
            ]);
        }

        $user->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);

        return response()->json([
            'promoted' => $wasPartner,
            'offline' => true,
            'user' => new AuthUserResource($user),
            'subscription' => $user->subscription('default'),
        ], 201);
    }

    public function webhook(
        Request $request,
        PaddleSignatureVerifier $signatures,
        HandlePaddleWebhookAction $action,
    ): JsonResponse {
        $raw = $request->getContent();
        $header = (string) $request->header('Paddle-Signature', '');

        if (! $signatures->valid($header, $raw)) {
            return response()->json(['message' => 'Firma inválida.'], Response::HTTP_FORBIDDEN);
        }

        $payload = $request->all();
        if (! is_array($payload) || ! isset($payload['event_type'])) {
            return response()->json(['message' => 'Evento inválido.'], 422);
        }

        $action->handle($payload);

        return response()->json(['received' => true]);
    }
}
