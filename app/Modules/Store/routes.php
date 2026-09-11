<?php

declare(strict_types=1);

use App\Modules\Store\Http\Controllers\InventoryAllocationController;
use App\Modules\Store\Http\Controllers\NotificationController;
use App\Modules\Store\Http\Controllers\OrderController;
use App\Modules\Store\Http\Controllers\ProductController;
use App\Modules\Store\Http\Controllers\StoreCategoryController;
use App\Modules\Store\Http\Controllers\StoreReportController;
use App\Modules\Store\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

Route::get('store/{slug}', [StoreController::class, 'show'])->middleware('throttle:public');
Route::get('store/{slug}/shipping-quote', [StoreController::class, 'shippingQuote'])->middleware('throttle:public');
Route::get('store/{slug}/products/{productSlug}', [ProductController::class, 'showPublic'])->middleware('throttle:public');
Route::post('store/{slug}/orders', [OrderController::class, 'store'])->middleware('throttle:checkout');

Route::middleware(['auth:sanctum', 'two_factor'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

    Route::get('my-store', [StoreController::class, 'myStore']);
    Route::get('my-store/orders', [OrderController::class, 'index']);
    Route::get('my-store/orders/{id}/voucher', [OrderController::class, 'voucher']);
    Route::get('my-store/reports/inventory', [StoreReportController::class, 'inventory'])
        ->middleware('permission:product.manage');
    Route::get('my-store/reports/sales', [StoreReportController::class, 'sales']);

    Route::middleware(['permission:store.manage', 'subscription'])->group(function () {
        Route::put('my-store', [StoreController::class, 'update']);
        Route::post('my-store/apply-target-margin', [StoreController::class, 'applyTargetMargin']);
        Route::post('my-store/payment-assets', [StoreController::class, 'storePaymentAsset'])->middleware('throttle:uploads');
        Route::get('my-store/shipping-quote', [StoreController::class, 'myShippingQuote']);
        Route::post('my-store/orders', [OrderController::class, 'storePos']);
        Route::post('my-store/orders/{id}/pay', [OrderController::class, 'markPaid']);
        Route::get('my-store/inventory/allocations', [InventoryAllocationController::class, 'index']);
        Route::post('my-store/inventory/allocations', [InventoryAllocationController::class, 'store']);
        Route::post('my-store/inventory/allocations/{id}/return', [InventoryAllocationController::class, 'returnStock']);
        Route::get('my-store/categories', [StoreCategoryController::class, 'index']);
        Route::post('my-store/categories', [StoreCategoryController::class, 'store']);
        Route::put('my-store/categories/{id}', [StoreCategoryController::class, 'update']);
        Route::delete('my-store/categories/{id}', [StoreCategoryController::class, 'destroy']);
    });

    Route::middleware('permission:product.manage')->group(function () {
        Route::get('products', [ProductController::class, 'index']);
    });

    Route::middleware(['permission:product.manage', 'subscription'])->group(function () {
        Route::post('products', [ProductController::class, 'store']);
        Route::post('products/import', [ProductController::class, 'import'])->middleware('throttle:uploads');
        Route::put('products/{id}', [ProductController::class, 'update']);
        Route::delete('products/{id}', [ProductController::class, 'destroy']);
    });
});
