<?php

namespace App\Modules;

use App\Hooks\Hook;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleAutoloader::class);
        $this->app->singleton(ModuleManager::class);
        $this->app->singleton(ModuleLoader::class);

        // Modules register alongside the app's own providers, so their
        // bindings exist before anything boots.
        $this->app->make(ModuleLoader::class)->registerEnabled();
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/modules.php'));

        $this->app->make(ModuleLoader::class)->bootLoaded();

        // For modules that build on each other: fired once every provider,
        // core and module, has booted.
        $this->app->booted(fn () => Hook::doAction('core.modules:init'));
    }
}
