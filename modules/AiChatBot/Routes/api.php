<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;


Route::middleware(['auth:sanctum'])->prefix('/ai-chatbot')
    ->group(function () {
        
});
