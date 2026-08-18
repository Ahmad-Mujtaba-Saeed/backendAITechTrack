<?php

use Illuminate\Support\Facades\Route;
use Modules\AiChatBot\Http\Controllers\ChatBotController;
use Modules\AiChatBot\Http\Controllers\Internal\AgentTokenController;
use Modules\AiChatBot\Http\Controllers\Internal\AgentApiController;

Route::middleware(['auth:sanctum'])->prefix('/ai-chatbot')
    ->group(function () {
        Route::get('/', [ChatBotController::class, 'index']);
        Route::post('/chat', [ChatBotController::class, 'chat']);
        Route::post('/chat/resume', [ChatBotController::class, 'resume']);
    });

Route::prefix('internal')->group(function () {
    Route::get('/validate-agent-token', [AgentTokenController::class,'validate']);

    Route::prefix('user')->group(function (){
        Route::get('/profile', [AgentApiController::class, 'getUserProfile']);
        });
    });

    Route::prefix('resume')->middleware('agent:resume.manage')->group(function () {
        Route::post('/create-empty', [AgentApiController::class, 'createEmpty']);
    });

    Route::middleware('agent:subscription.manage')->group(function () {
        Route::get('/get-user-subscription', [AgentApiController::class, 'getUserSubscription']);
        Route::post('/cancel-user-subscription', [AgentApiController::class, 'cancelUserSubscription']);
    });
