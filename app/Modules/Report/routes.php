<?php

declare(strict_types=1);

use App\Modules\Report\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'two_factor'])->group(function () {
    Route::get('reports/monthly-closing', [ReportController::class, 'monthlyClosing'])
        ->middleware(['permission:report.view', 'subscription', 'plan.feature:closing']);
    Route::put('reports/monthly-closing/goals', [ReportController::class, 'upsertGoals'])
        ->middleware(['permission:report.view', 'subscription', 'plan.feature:closing']);
    Route::get('reports/download', [ReportController::class, 'downloadReport'])
        ->middleware(['permission:report.generate', 'subscription', 'plan.feature:closing']);
});
