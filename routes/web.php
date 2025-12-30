<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-route', function() {
    return response()->json([
        'routes' => \Route::getRoutes()->getRoutes()
    ]);
});



Route::get('/migrate', function () {
    \Illuminate\Support\Facades\Artisan::call('migrate');
    return "Migrate Complete!";
});


Route::get('/seed', function () {
    \Illuminate\Support\Facades\Artisan::call('db:seed');
    return "Seed Complete!";
});
Route::get('/optimize-clear', function () {
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    return "Optimize Clear Complete!";
});

Route::get('/storage-link', function () {
    if (file_exists(public_path('storage'))) {
        return 'Storage link already exists!';
    }
    
    \Illuminate\Support\Facades\Artisan::call('storage:link');
    return 'Storage link created successfully!';
});



Route::get('/test-email', function () {
    Mail::raw('This is a test email to verify SMTP credentials.', function ($message) {
        $message->to('ahmadmujtabap70@gmail.com')
                ->subject('SMTP Test Email');
    });

    return response()->json([
        'status' => 'success',
        'message' => 'Test email sent successfully.'
    ]);
});


Route::get('/logs', function () {
    $logFile = storage_path('logs/laravel.log');

    if (!File::exists($logFile)) {
        return response('Log file not found.', 404);
    }

    // Read last 500 lines for performance
    $lines = explode("\n", File::get($logFile));
    $lastLines = array_slice($lines, -500);

    return Response::make(
        nl2br(e(implode("\n", $lastLines))),
        200,
        ['Content-Type' => 'text/html']
    );
});




Route::get('/test-module', function() {
    return response()->json([
        'module_exists' => class_exists(\Modules\Billing\ModuleServiceProvider::class),
        'billing_routes' => file_exists(base_path('Modules/Billing/Routes/api.php'))
    ]);
});