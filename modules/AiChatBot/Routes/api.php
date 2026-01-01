<?php

use Illuminate\Support\Facades\Route;
use Modules\AiChatBot\Http\Controllers\ChatBotController;
use Modules\AiChatBot\Http\Controllers\Internal\AgentTokenController;
use Modules\AiChatBot\Http\Controllers\Internal\AgentApiController;

Route::middleware(['auth:sanctum'])->prefix('/ai-chatbot')
    ->group(function () {
        Route::get('/', [ChatBotController::class, 'index']);
        Route::post('/chat', [ChatBotController::class, 'chat']);
    });

Route::prefix('internal')->group(function () {
    Route::get('/validate-agent-token', [AgentTokenController::class,'validate']);
});

Route::prefix('internal')->middleware('agent:subscription.read')->group(function () {
    Route::get('/get-user-subscription', [AgentApiController::class, 'getUserSubscription']);
});
