<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\DynamicMailConfigProvider::class,
    Barryvdh\DomPDF\ServiceProvider::class,
    Modules\AccessControl\ModuleServiceProvider::class,
    Modules\Admin\ModuleServiceProvider::class,
    Modules\Auth\ModuleServiceProvider::class,
    Modules\Billing\ModuleServiceProvider::class,
    Modules\Resume\ModuleServiceProvider::class,
    Modules\User\ModuleServiceProvider::class,
];
