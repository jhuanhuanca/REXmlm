<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\CatalogProxyController;
use App\Modules\Admin\Http\Controllers\CommissionController;
use App\Modules\Admin\Http\Controllers\PlanController;
use App\Modules\Admin\Http\Controllers\ReportController;
use App\Modules\Admin\Http\Controllers\UserController;
use App\Modules\Admin\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'two_factor', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::get('users/{id}', [UserController::class, 'show']);
    Route::put('users/{id}', [UserController::class, 'update']);
    Route::delete('users/{id}', [UserController::class, 'destroy']);

    Route::get('plans', [PlanController::class, 'index']);
    Route::post('plans', [PlanController::class, 'store']);
    Route::put('plans/{id}', [PlanController::class, 'update']);
    Route::delete('plans/{id}', [PlanController::class, 'destroy']);

    Route::get('commissions', [CommissionController::class, 'index']);
    Route::post('commissions/{id}/pay', [CommissionController::class, 'pay']);
    Route::post('commissions/{id}/cancel', [CommissionController::class, 'cancel']);

    Route::get('withdrawals', [WithdrawalController::class, 'index']);
    Route::post('withdrawals/{id}/pay', [WithdrawalController::class, 'pay']);
    Route::post('withdrawals/{id}/reject', [WithdrawalController::class, 'reject']);

    Route::get('reports/overview', [ReportController::class, 'overview']);

    Route::any('catalog/{path}', [CatalogProxyController::class, 'handle'])->where('path', '.*');
});
