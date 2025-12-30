<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Admin\Models\CoreCredential;

class DynamicMailConfigProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
public function boot()
{
    // Check if the core_credentials table exists before trying to query it
    if (!\Schema::hasTable('core_credentials')) {
        return;
    }

    try {
        $settings = CoreCredential::where('group', 'mail')
            ->get()
            ->mapWithKeys(function ($item) {
                // Use getRawOriginal to get the raw value without decryption
                return [$item->key => $item->is_encrypted 
                    ? $item->getRawOriginal('value') 
                    : $item->value];
            })
            ->toArray();

        // Only update config if we have settings
        if (!empty($settings)) {
            config([
                'mail.mailers.smtp.host' => $settings['mail.host'] ?? config('mail.mailers.smtp.host'),
                'mail.mailers.smtp.port' => $settings['mail.port'] ?? config('mail.mailers.smtp.port'),
                'mail.mailers.smtp.username' => $settings['mail.username'] ?? config('mail.mailers.smtp.username'),
                'mail.mailers.smtp.password' => $settings['mail.password'] ?? config('mail.mailers.smtp.password'),
                'mail.mailers.smtp.encryption' => $settings['mail.encryption'] ?? config('mail.mailers.smtp.encryption'),
                'mail.from.address' => $settings['mail.from.address'] ?? config('mail.from.address'),
                'mail.from.name' => $settings['mail.from.name'] ?? config('mail.from.name'),
            ]);
        }
    } catch (\Exception $e) {
        \Log::error('Failed to load mail configuration from database', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
}
