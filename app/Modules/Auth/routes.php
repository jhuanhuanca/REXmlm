<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\RegistrationOptionsController;
use App\Modules\Auth\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::get('registration-options', RegistrationOptionsController::class)->middleware('throttle:60,1');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('google', [AuthController::class, 'google'])->middleware('throttle:auth');

    Route::middleware(['auth:sanctum', 'two_factor'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('user', [AuthController::class, 'me']);

        Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:auth');
        Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:auth');
        Route::post('two-factor/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:auth');
        Route::post('two-factor/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:auth');
    });
});
