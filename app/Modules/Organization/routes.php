<?php

declare(strict_types=1);

use App\Modules\Organization\Http\Controllers\LeaderConnectionController;
use App\Modules\Organization\Http\Controllers\MyCompaniesController;
use App\Modules\Organization\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'two_factor', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'store']);
    Route::get('organizations/{id}', [OrganizationController::class, 'show']);
    Route::put('organizations/{id}/metrics-profile', [OrganizationController::class, 'updateMetricsProfile']);
    Route::post('organizations/{id}/connections', [OrganizationController::class, 'storeConnection']);
    Route::post('organizations/{id}/connections/{connectionId}/sync', [OrganizationController::class, 'syncConnection']);
    Route::delete('organizations/{id}/connections/{connectionId}', [OrganizationController::class, 'destroyConnection']);
});

Route::middleware(['auth:sanctum', 'two_factor', 'role:leader', 'subscription'])->group(function () {
    Route::post('my-companies', [MyCompaniesController::class, 'store']);
    Route::put('my-companies/active', [MyCompaniesController::class, 'activate']);
});

Route::middleware(['auth:sanctum', 'two_factor', 'permission:report.generate'])->group(function () {
    Route::post('reports/connections/import', [LeaderConnectionController::class, 'import'])->middleware('throttle:uploads');
});
