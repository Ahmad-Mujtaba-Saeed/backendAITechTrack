<?php

use Illuminate\Support\Facades\Route;
use Modules\Resume\Http\Controllers\ResumeController;
use Modules\Resume\Http\Controllers\ATSController;

Route::middleware(['auth:sanctum'])->prefix('/resume')
    ->group(function () {
    Route::get('/',[ResumeController::class, 'index']);
    Route::post('/create-empty', [ResumeController::class, 'createEmpty']);
    Route::post('/parse-resume', [ResumeController::class, 'parseResumeOCRPyScript']);
    // Route::post('/parse-resume-gpt', [ResumeController::class, 'parseResumeGPT']);
    Route::get('/{id}', [ResumeController::class, 'show']);
    Route::put('/{id}', [ResumeController::class, 'update']);
    Route::delete('/{id}', [ResumeController::class, 'delete']);

    Route::get('/{id}/download', [ResumeController::class, 'download']);
    Route::post('/{id}/ats-check', [ATSController::class, 'check']);
    Route::post('/{id}/job-match', [ATSController::class, 'matchJob']);

    // Previously registered outside this group, and so reachable with no
    // authentication at all: anyone could walk the id range and download any
    // user's CV, name, email, phone and address included. It was placed outside
    // because the client opened it with window.open, which cannot send the
    // bearer token. The client now builds the DOCX itself, so the endpoint can
    // sit behind auth like every other resume route.
    Route::get('/{id}/download-doc', [ResumeController::class, 'downloadDoc']);
});