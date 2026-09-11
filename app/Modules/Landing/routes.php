<?php

declare(strict_types=1);

use App\Modules\Landing\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('landing/{slug}', [LandingController::class, 'showPublic'])->middleware('throttle:public');

Route::middleware(['auth:sanctum', 'two_factor'])->group(function () {
    Route::get('my-landing', [LandingController::class, 'show']);

    Route::middleware(['permission:landing.manage', 'subscription'])->group(function () {
        Route::put('my-landing', [LandingController::class, 'update']);
        Route::post('my-landing/assets', [LandingController::class, 'storeAsset'])->middleware('throttle:uploads');
        Route::post('my-landing/toggle-publish', [LandingController::class, 'togglePublish']);
    });
});
