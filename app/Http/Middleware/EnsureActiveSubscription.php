<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ($user->hasActivePlatformAccess()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Tu suscripción no está al día. Paga el plan para seguir usando tienda, landing y equipo.',
            'code' => 'subscription_past_due',
        ], 403);
    }
}
