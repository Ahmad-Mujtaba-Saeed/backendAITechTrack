<?php

use Illuminate\Support\Facades\Route;


Route::get('/test', function () {
    return response()->json(['message' => 'API is working']);
});
Route::prefix('v1')->group(function () {
    
    Route::middleware('auth:sanctum')->group(function () {

    });
    
});