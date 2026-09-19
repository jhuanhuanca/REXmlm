<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Subscription\Services\PlanEntitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if (PlanEntitlements::allows($user, $feature)) {
            return $next($request);
        }

        if ($user->hasRole('leader') && ! $user->hasPaidPlatformAccess()) {
            return response()->json([
                'message' => 'Tu suscripción no está al día. Paga el plan para seguir usando el panel de líder.',
                'code' => 'subscription_past_due',
            ], 403);
        }

        return response()->json([
            'message' => 'Tu plan no incluye este módulo. Pasa a Intermedio o Premium para activarlo.',
            'code' => 'plan_feature_required',
            'feature' => $feature,
        ], 403);
    }
}
