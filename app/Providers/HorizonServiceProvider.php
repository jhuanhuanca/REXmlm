<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(function ($request) {
            if (app()->environment('local')) {
                return true;
            }

            $user = $request->user()
                ?? Auth::guard('sanctum')->user();

            return $user?->hasRole('admin') ?? false;
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user?->hasRole('admin') ?? false;
        });
    }
}
