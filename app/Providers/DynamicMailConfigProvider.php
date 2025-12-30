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
        $settings = CoreCredential::where('group', 'mail')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->key => $item->value]; // This will trigger the getValueAttribute accessor
            });

        config([
            'mail.mailers.smtp.host' => $settings['mail.host'] ?? null,
            'mail.mailers.smtp.port' => $settings['mail.port'] ?? null,
            'mail.mailers.smtp.username' => $settings['mail.username'] ?? null,
            'mail.mailers.smtp.password' => $settings['mail.password'] ?? null, // No need for decrypt() here
            'mail.mailers.smtp.encryption' => $settings['mail.encryption'] ?? null,
            'mail.from.address' => $settings['mail.from.address'] ?? null,
            'mail.from.name' => $settings['mail.from.name'] ?? null,
        ]);
    }

}
