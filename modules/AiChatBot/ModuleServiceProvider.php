<?php

namespace Modules\AiChatBot;

use App\Core\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleName = 'AiChatBot';

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = base_path('modules/AiChatBot');
    }
}
