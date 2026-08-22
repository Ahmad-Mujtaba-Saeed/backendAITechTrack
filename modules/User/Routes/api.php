<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Controllers\UserManagementController;
use Modules\User\Http\Controllers\ContactController;


Route::middleware(['auth:sanctum'])->prefix('/user')
    ->group(function () {
    Route::post('/profile-settings', [UserController::class, 'ProfileSettings']);
});


Route::middleware(['auth:sanctum'])
    ->group(function () {
    Route::post('/change-password', [UserController::class, 'changePassword']);
});

Route::middleware(['auth:sanctum','permission:manage-users'])->prefix('/users/management')
    ->group(function () {
    Route::get('/', [UserManagementController::class, 'index']);
    Route::post('/toggle-user-enable-disable/{user_id}', [UserManagementController::class, 'toggle_user_enable_disable']);

    
    Route::get('/subscription-status/{user_id}', [UserManagementController::class, 'userSubscriptionStatus']);
    Route::post('/cancel-subscription-immediate/{user_id}', [UserManagementController::class, 'cancelSubscriptionImmediate']);
    Route::delete('/delete/{user_id}', [UserManagementController::class, 'delete']);
});

Route::post('/contact', [ContactController::class, 'store']);

Route::get('/support', [UserController::class, 'support'])->name('support');
Route::get('/docs', [UserController::class, 'docs'])->name('docs');
