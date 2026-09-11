<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->is('horizon', 'horizon/*')) {
            return $response;
        }

        $this->set($response, 'X-Content-Type-Options', 'nosniff');
        $this->set($response, 'X-Frame-Options', 'DENY');
        $this->set($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->set(
            $response,
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        );
        $this->set(
            $response,
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        );

        if ($this->shouldSendHsts($request)) {
            $this->set($response, 'Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    private function shouldSendHsts(Request $request): bool
    {
        if (app()->environment('local', 'testing')) {
            return false;
        }

        return $request->secure() || app()->environment('production');
    }

    private function set(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
