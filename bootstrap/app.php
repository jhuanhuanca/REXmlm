<?php

use App\Http\Middleware\EnsureActiveSubscription;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
        ->withCommands([
            App\Modules\Store\Console\ScanInventoryAlertsCommand::class,
            App\Modules\Store\Console\PrepareLoadTestStoreCommand::class,
            App\Modules\Store\Console\SyncCompanyCatalogCommand::class,
            App\Console\GoLiveCheckCommand::class,
            App\Modules\Subscription\Console\GrantComplimentarySubscriptionCommand::class,
            App\Modules\Subscription\Console\NotifySubscriptionLifecycleCommand::class,
            App\Modules\Organization\Console\RefreshUserCatalogCompaniesCommand::class,
            App\Modules\MLM\Console\ExpireInvitationsCommand::class,
        ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\EnsureProductionHardened::class);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->throttleApi();

        $middleware->alias([
            'two_factor' => \App\Http\Middleware\EnsureTwoFactorCompleted::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'subscription' => EnsureActiveSubscription::class,
            'plan.feature' => \App\Http\Middleware\EnsurePlanFeature::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'stripe/*',
            'paddle/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
