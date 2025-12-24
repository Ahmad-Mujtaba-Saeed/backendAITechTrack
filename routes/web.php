<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-route', function() {
    return response()->json([
        'routes' => \Route::getRoutes()->getRoutes()
    ]);
});


Route::get('/test-module', function() {
    return response()->json([
        'module_exists' => class_exists(\Modules\Billing\ModuleServiceProvider::class),
        'billing_routes' => file_exists(base_path('Modules/Billing/Routes/api.php'))
    ]);
});