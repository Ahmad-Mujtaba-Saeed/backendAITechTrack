<?php

namespace Modules\Resume;

use App\Core\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'Resume';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/Resume');
    }

    public function boot()
    {
        parent::boot();
        
        // Create views directory if it doesn't exist
        $viewsPath = $this->modulePath . '/Resources/views';
        if (!is_dir($viewsPath)) {
            mkdir($viewsPath, 0755, true);
        }
        
        $this->loadViewsFrom(
            $viewsPath,
            'resume'
        );
    }

}
