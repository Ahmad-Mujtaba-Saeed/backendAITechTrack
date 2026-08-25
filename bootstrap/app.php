<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use modules\Resume\Exceptions\ATSAnalysisException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'agent' => \App\Http\Middleware\RequireAgentToken::class,
            'billable' => \App\Http\Middleware\EnsureBillableUser::class,
        ]);
    })
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (ATSAnalysisException $e, $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $e->getSafeMessage(),
                'request_id' => $e->getRequestId(),
            ], 503);
        }
    });
})->create();
