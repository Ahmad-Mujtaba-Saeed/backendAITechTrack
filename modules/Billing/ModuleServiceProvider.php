<?php

namespace Modules\Billing;

use App\Core\BaseModuleServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'Billing';
    protected string $moduleNameLower = 'billing';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/Billing');
    }

    public function boot()
    {
        parent::boot();
        
        $this->registerViews();
    }

    protected function registerViews()
    {
        $viewsPath = $this->modulePath . '/resources/views';
        Log::info('Registering views', [
            'module' => $this->moduleName,
            'path' => $viewsPath,
            'exists' => is_dir($viewsPath) ? 'yes' : 'no'
        ]);

        if (!File::isDirectory($viewsPath)) {
            Log::info('Creating views directory', ['path' => $viewsPath]);
            File::makeDirectory($viewsPath, 0755, true);
        }

        $this->loadViewsFrom($viewsPath, $this->moduleName);
        $this->loadViewsFrom($viewsPath, $this->moduleNameLower);

        Log::info('Views registered', [
            'namespaces' => [
                $this->moduleName => $viewsPath,
                $this->moduleNameLower => $viewsPath
            ]
        ]);
    }
}
