<?php

namespace Modules\User;

use App\Core\BaseModuleServiceProvider;
use Illuminate\Support\Facades\File;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'User';
    protected string $moduleNameLower = 'user';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/User');
    }

    public function boot()
    {
        parent::boot();

        $this->registerViews();
    }

    protected function registerViews()
    {
        $viewsPath = $this->modulePath . '/resources/views';

        if (!File::isDirectory($viewsPath)) {
            return;
        }

        $this->loadViewsFrom($viewsPath, $this->moduleName);
        $this->loadViewsFrom($viewsPath, $this->moduleNameLower);
    }
}
