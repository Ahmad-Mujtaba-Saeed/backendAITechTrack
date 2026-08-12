<?php

use Illuminate\Support\Facades\Route;
use Modules\AccessControl\Http\Controllers\PermissionController;
use Modules\AccessControl\Http\Controllers\RoleController;
use Modules\AccessControl\Http\Controllers\AccessControlController;

Route::middleware(['auth:sanctum', 'permission:manage-roles'])
    ->prefix('access-control')
    ->group(function () {
        Route::apiResource('permissions', PermissionController::class);
        Route::apiResource('roles', RoleController::class);

        // User-role assignment management
        Route::get('user-roles', [AccessControlController::class, 'indexUserRoles']);
        Route::post('user-roles', [AccessControlController::class, 'assignRoleToUser']);
        Route::delete('user-roles', [AccessControlController::class, 'removeRoleFromUser']);
        Route::put('user-roles/sync', [AccessControlController::class, 'syncUserRoles']);
    });