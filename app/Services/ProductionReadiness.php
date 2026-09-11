<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Subscription\Models\Plan;
use Throwable;

class ProductionReadiness
{
    /**
     * @return list<array{level: string, code: string, message: string}>
     */
    public function issues(bool $asProduction = false): array
    {
        $production = $asProduction || app()->environment('production');
        $issues = [];

        $debug = (bool) config('app.debug');
        $appUrl = (string) config('app.url');
        $appKey = (string) config('app.key');
        $offline = (bool) config('billing.offline');
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');
        $catalogToken = (string) config('services.catalog.token');
        $cors = config('cors.allowed_origins');
        $corsList = is_array($cors) ? $cors : [];
        $stateful = array_values(array_filter(array_map(
            'trim',
            is_array(config('sanctum.stateful')) ? config('sanctum.stateful') : [],
        )));
        $paddleKey = (string) config('services.paddle.api_key');
        $paddleWebhook = (string) config('services.paddle.webhook_secret');
        $stripeSecret = (string) config('cashier.secret');
        $stripeWebhook = (string) config('cashier.webhook.secret');

        if ($production && $debug) {
            $issues[] = $this->fail('app_debug', 'APP_DEBUG debe ser false en production.');
        }

        if ($production && $appKey === '') {
            $issues[] = $this->fail('app_key', 'Falta APP_KEY.');
        }

        if ($production && ! str_starts_with($appUrl, 'https://')) {
            $issues[] = $this->fail('app_url', 'APP_URL debe ser https://…');
        }

        if ($production && $offline) {
            $issues[] = $this->fail('billing_offline', 'BILLING_OFFLINE no puede estar activo en production.');
        }

        if ($production && in_array($queue, ['sync', ''], true)) {
            $issues[] = $this->fail('queue', 'QUEUE_CONNECTION no puede ser sync en production (usa redis).');
        }

        if ($production && in_array($cache, ['file', 'array', ''], true)) {
            $issues[] = $this->fail('cache', 'CACHE_STORE no puede ser file/array en production (usa redis).');
        }

        if ($production && $this->weakToken($catalogToken)) {
            $issues[] = $this->fail(
                'catalog_token',
                'PRODUCT_SERVICE_TOKEN debe tener ≥32 caracteres y no ser un valor de ejemplo.',
            );
        }

        if ($production && $this->corsLooksLocal($corsList)) {
            $issues[] = $this->fail(
                'cors',
                'CORS_ALLOWED_ORIGINS no debe incluir localhost en production.',
            );
        }

        if ($production && $stateful !== []) {
            $issues[] = $this->fail(
                'sanctum_stateful',
                'SANCTUM_STATEFUL_DOMAINS debe quedar vacío mientras el auth sea Bearer.',
            );
        }

        $hasPaddle = $paddleKey !== '';
        $hasStripe = is_string($stripeSecret) && $stripeSecret !== '';

        if ($production && ! $offline && ! $hasPaddle && ! $hasStripe) {
            $issues[] = $this->fail(
                'billing_keys',
                'Configura PADDLE_API_KEY (o STRIPE_SECRET) antes de cobrar.',
            );
        }

        if ($production && $hasPaddle && $paddleWebhook === '') {
            $issues[] = $this->fail('paddle_webhook', 'Falta PADDLE_WEBHOOK_SECRET.');
        }

        if ($production && $hasStripe && (! is_string($stripeWebhook) || $stripeWebhook === '')) {
            $issues[] = $this->fail('stripe_webhook', 'Falta STRIPE_WEBHOOK_SECRET.');
        }

        if ($production && (string) config('mail.default') === 'log') {
            $issues[] = $this->warn('mail', 'MAIL_MAILER=log no envía invitaciones ni avisos reales.');
        }

        if ($production && (string) config('session.driver') === 'file') {
            $issues[] = $this->warn('session', 'SESSION_DRIVER=file es aceptable con Bearer; Redis es opcional.');
        }

        if ($production) {
            try {
                $priced = Plan::query()->active()->get()->contains(
                    fn (Plan $plan) => $plan->paddlePriceId() !== null,
                );

                if (! $priced) {
                    $issues[] = $this->warn(
                        'plans',
                        'Ningún plan activo tiene paddle_price_id (o stripe_price_id). El checkout de Paddle fallará.',
                    );
                }
            } catch (Throwable) {
                $issues[] = $this->warn('plans', 'No se pudieron leer planes (¿migraciones pendientes?).');
            }
        }

        return $issues;
    }

    /**
     * @return list<array{level: string, code: string, message: string}>
     */
    public function fatal(bool $asProduction = false): array
    {
        return array_values(array_filter(
            $this->issues($asProduction),
            fn (array $row) => $row['level'] === 'fail',
        ));
    }

    /**
     * @param  list<string>  $origins
     */
    private function corsLooksLocal(array $origins): bool
    {
        foreach ($origins as $origin) {
            $origin = strtolower((string) $origin);
            if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
                return true;
            }
        }

        return false;
    }

    private function weakToken(string $token): bool
    {
        $token = trim($token);

        if (strlen($token) < 32) {
            return true;
        }

        $banned = [
            'change-me',
            'change-me-local-catalog',
            'secret',
            'password',
            'test',
            'token',
        ];

        return in_array(strtolower($token), $banned, true);
    }

    /**
     * @return array{level: string, code: string, message: string}
     */
    private function fail(string $code, string $message): array
    {
        return ['level' => 'fail', 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{level: string, code: string, message: string}
     */
    private function warn(string $code, string $message): array
    {
        return ['level' => 'warn', 'code' => $code, 'message' => $message];
    }
}
