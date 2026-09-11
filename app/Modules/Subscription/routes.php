<?php

declare(strict_types=1);

use App\Modules\Subscription\Http\Controllers\PlanController;
use App\Modules\Subscription\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('plans', [PlanController::class, 'index']);
Route::post('billing/webhook', [SubscriptionController::class, 'webhook']);

Route::middleware(['auth:sanctum', 'two_factor'])->group(function () {
    Route::get('subscriptions/current', [SubscriptionController::class, 'current']);
    Route::post('subscriptions', [SubscriptionController::class, 'store']);
});
