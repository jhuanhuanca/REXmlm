<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Commission\Models\WithdrawalRequest;
use App\Modules\Commission\Policies\WithdrawalRequestPolicy;
use App\Modules\Landing\Models\LandingPage;
use App\Modules\Landing\Policies\LandingPagePolicy;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Referral;
use App\Modules\MLM\Policies\InvitationPolicy;
use App\Modules\MLM\Policies\ReferralPolicy;
use App\Modules\Store\Models\InventoryAllocation;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreProductCategory;
use App\Modules\Store\Policies\InventoryAllocationPolicy;
use App\Modules\Store\Policies\OrderPolicy;
use App\Modules\Store\Policies\ProductPolicy;
use App\Modules\Store\Policies\StorePolicy;
use App\Modules\Store\Policies\StoreProductCategoryPolicy;
use App\Modules\Subscription\Models\Subscription;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Cashier::useSubscriptionModel(Subscription::class);

        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(InventoryAllocation::class, InventoryAllocationPolicy::class);
        Gate::policy(StoreProductCategory::class, StoreProductCategoryPolicy::class);
        Gate::policy(LandingPage::class, LandingPagePolicy::class);
        Gate::policy(Referral::class, ReferralPolicy::class);
        Gate::policy(Invitation::class, InvitationPolicy::class);
        Gate::policy(WithdrawalRequest::class, WithdrawalRequestPolicy::class);

        $this->configureRateLimiters();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl((string) config('app.url'));
        }
    }

    private function configureRateLimiters(): void
    {
        if ($this->app->runningUnitTests()) {
            RateLimiter::for('api', fn () => Limit::none());
            RateLimiter::for('auth', fn () => Limit::none());
            RateLimiter::for('public', fn () => Limit::none());
            RateLimiter::for('uploads', fn () => Limit::none());
            RateLimiter::for('checkout', fn () => Limit::none());

            return;
        }

        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/v1/billing/webhook')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->ip());
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(20)->by((string) $request->ip());
        });
    }
}
