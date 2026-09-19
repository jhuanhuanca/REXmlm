<?php

declare(strict_types=1);

use App\Modules\Tools\Http\Controllers\CompanyToolsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'two_factor', 'plan.feature:tools'])->group(function (): void {
    Route::get('tools/imc-packages', [CompanyToolsController::class, 'imcPackages']);
    Route::get('tools/wellness-needs', [CompanyToolsController::class, 'wellnessNeeds']);
    Route::get('tools/documents', [CompanyToolsController::class, 'documents']);
});
