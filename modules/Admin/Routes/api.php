<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\DashboardContrller;
use Modules\Admin\Http\Controllers\CoreCredentialsController;

Route::middleware(['auth:sanctum','permission:manage-core-credentials'])
    ->prefix('admin/core-credentials')
    ->group(function () {

        // List all credentials (optional filter by group)
        Route::get('/', [CoreCredentialsController::class, 'index'])->name('core-credentials.index');

        // Get single credential by key
        Route::get('/{key}', [CoreCredentialsController::class, 'show'])->name('core-credentials.show');

        // Create or update credential
        Route::post('/', [CoreCredentialsController::class, 'store'])->name('core-credentials.store');

        // Delete credential by key
        Route::delete('/{key}', [CoreCredentialsController::class, 'destroy'])->name('core-credentials.destroy');
});


Route::middleware(['auth:sanctum','permission:view-dashboard'])
    ->prefix('admin/dashboard')
    ->group(function () {
        Route::get('/payments-data', [DashboardContrller::class, 'getPaymentsData'])->name('payments-data.index');
        Route::get('/recent-subscriptions', [DashboardContrller::class, 'recentSubscriptions'])->name('recent-subscriptions.index');
    });