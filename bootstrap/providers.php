<?php

return [
    App\Providers\AppServiceProvider::class,
    \Modules\Auth\ModuleServiceProvider::class, 
    \Modules\Billing\ModuleServiceProvider::class,
    \Modules\AccessControl\ModuleServiceProvider::class,
];
