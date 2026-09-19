<?php

declare(strict_types=1);

use App\Modules\MLM\Http\Controllers\DashboardController;
use App\Modules\MLM\Http\Controllers\InvitationController;
use App\Modules\MLM\Http\Controllers\TeamMemberController;
use App\Modules\MLM\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::get('invitations/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:auth');

Route::middleware(['auth:sanctum', 'two_factor'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');
    Route::get('dashboard/team', [DashboardController::class, 'team'])->middleware('permission:team.view');
    Route::get('dashboard/team/roster', [TeamMemberController::class, 'roster'])->middleware('permission:team.view');
    Route::get('dashboard/team/reports', [TeamMemberController::class, 'export'])->middleware('permission:team.view');
    Route::post('dashboard/team/company-partners', [TeamMemberController::class, 'storeCompanyPartner'])
        ->middleware(['permission:team.view', 'subscription', 'plan.feature:team']);
    Route::post('dashboard/team/company-partners/{id}/convert', [TeamMemberController::class, 'convertCompanyPartner'])
        ->middleware(['permission:invitation.create', 'subscription', 'plan.feature:team']);
    Route::get('dashboard/team/{id}', [TeamMemberController::class, 'show'])->middleware('permission:team.view');
    Route::put('dashboard/team/{id}', [TeamMemberController::class, 'update'])->middleware('permission:team.view');
    Route::post('dashboard/team/{id}/activities', [TeamMemberController::class, 'storeActivity'])->middleware('permission:team.view');
    Route::get('dashboard/commissions', [DashboardController::class, 'commissions'])->middleware('permission:commission.view');
    Route::get('dashboard/withdrawals/balance', [WithdrawalController::class, 'balance'])->middleware('permission:commission.view');
    Route::get('dashboard/withdrawals', [WithdrawalController::class, 'index'])->middleware('permission:commission.view');
    Route::post('dashboard/withdrawals', [WithdrawalController::class, 'store'])
        ->middleware(['permission:commission.view', 'throttle:6,1']);

    Route::post('invitations', [InvitationController::class, 'store'])
        ->middleware(['permission:invitation.create', 'subscription', 'plan.feature:team']);
});
