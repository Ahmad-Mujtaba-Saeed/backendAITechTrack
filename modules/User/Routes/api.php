<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Controllers\UserManagementController;


Route::middleware(['auth:sanctum'])->prefix('/user')
    ->group(function () {
    Route::post('/profile-settings', [UserController::class, 'ProfileSettings']);
});

Route::middleware(['auth:sanctum','permission:manage-users'])->prefix('/users/management')
    ->group(function () {
    Route::get('/', [UserManagementController::class, 'index']);
    
    Route::get('/subscription-status/{user_id}', [UserManagementController::class, 'userSubscriptionStatus']);
    Route::post('/cancel-subscription-immediate/{user_id}', [UserManagementController::class, 'cancelSubscriptionImmediate']);
    Route::delete('/delete/{user_id}', [UserManagementController::class, 'delete']);
});