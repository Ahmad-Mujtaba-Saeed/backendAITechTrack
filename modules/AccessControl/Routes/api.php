<?php

use Illuminate\Support\Facades\Route;
use Modules\AccessControl\Http\Controllers\PermissionController;
use Modules\AccessControl\Http\Controllers\RoleController;
use Modules\AccessControl\Http\Controllers\RolePermissionController;

Route::middleware(['auth:sanctum', 'permission:manage-roles'])
    ->prefix('access-control')
    ->group(function () {
        Route::apiResource('permissions', PermissionController::class);
        Route::apiResource('roles', RoleController::class);

        Route::post('assign-role', [RolePermissionController::class, 'assignRoleToUser']);
    });