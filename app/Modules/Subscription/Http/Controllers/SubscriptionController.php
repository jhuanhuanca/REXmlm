<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Resources\AuthUserResource;
use App\Modules\Commission\Actions\AccrueReferralSubscriptionCommission;
use App\Modules\MLM\Actions\PromotePartnerToLeaderAction;
use App\Modules\Subscription\Actions\HandlePaddleWebhookAction;
use App\Modules\Subscription\Http\Requests\StoreSubscriptionRequest;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Services\PaddleCheckoutService;
use App\Modules\Subscription\Services\PaddleClient;
use App\Modules\Subscription\Services\PaddleSignatureVerifier;
use App\Shared\Enums\NetworkStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscription('default');

        return response()->json([
            'subscription' => $subscription,
        ]);
    }

    public function store(
        StoreSubscriptionRequest $request,
        PromotePartnerToLeaderAction $promotePartner,
        AccrueReferralSubscriptionCommission $accrueCommission,
        PaddleClient $paddle,
        PaddleCheckoutService $checkout,
    ): JsonResponse {
        $plan = Plan::query()->active()->findOrFail($request->integer('plan_id'));
        $user = $request->user();
        $wasPartner = $user->hasRole(config('rexmlm.roles.partner'));
        $offline = (bool) config('billing.offline');

        if ($paddle->configured() && ! $offline) {
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

        $user->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);

        return response()->json([
            'promoted' => $wasPartner,
            'offline' => true,
            'user' => new AuthUserResource($user),
            'subscription' => null,
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
