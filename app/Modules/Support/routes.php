<?php

declare(strict_types=1);

use App\Modules\Support\Http\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::post('support/contact', [SupportTicketController::class, 'contact'])->middleware('throttle:20,1');

Route::middleware(['auth:sanctum', 'two_factor'])->prefix('support')->group(function (): void {
    Route::get('tickets', [SupportTicketController::class, 'index']);
    Route::post('tickets', [SupportTicketController::class, 'store']);
    Route::get('tickets/{id}', [SupportTicketController::class, 'show']);
    Route::post('tickets/{id}/replies', [SupportTicketController::class, 'reply']);
});

Route::middleware(['auth:sanctum', 'two_factor', 'role:admin'])->prefix('admin/support')->group(function (): void {
    Route::get('tickets', [SupportTicketController::class, 'adminIndex']);
    Route::get('tickets/{id}', [SupportTicketController::class, 'adminShow']);
    Route::put('tickets/{id}', [SupportTicketController::class, 'adminUpdate']);
    Route::post('tickets/{id}/replies', [SupportTicketController::class, 'adminReply']);
});
