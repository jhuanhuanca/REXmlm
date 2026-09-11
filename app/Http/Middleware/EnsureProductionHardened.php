<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProductionReadiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductionHardened
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production') || app()->runningUnitTests()) {
            return $next($request);
        }

        if ($request->is('up')) {
            return $next($request);
        }

        $fatal = app(ProductionReadiness::class)->fatal();

        if ($fatal === []) {
            return $next($request);
        }

        return response()->json([
            'message' => 'El entorno de production no cumple el checklist de go-live.',
            'code' => 'production_not_ready',
            'issues' => array_column($fatal, 'code'),
        ], 503);
    }
}
