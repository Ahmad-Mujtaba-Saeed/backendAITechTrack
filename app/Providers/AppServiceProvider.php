<?php

namespace App\Providers;
use App\Core\ModuleManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Log::info('AppServiceProvider booting...');
        ModuleManager::load();
        \Log::info('ModuleManager loaded');
        \Log::info('Registered providers:', app()->getLoadedProviders());
    }
}
