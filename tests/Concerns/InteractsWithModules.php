<?php

namespace Tests\Concerns;

use App\Models\Module;
use App\Modules\ModuleAutoloader;
use App\Modules\ModuleLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Module tests work in a temporary modules folder, never the real one,
 * with copies of the modules in tests/Fixtures/modules.
 */
trait InteractsWithModules
{
    protected string $modulesPath;

    protected function useTemporaryModulesFolder(): void
    {
        $this->modulesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-modules-'.Str::random(10);
        File::ensureDirectoryExists($this->modulesPath);
        config(['modules.path' => $this->modulesPath]);

        $this->beforeApplicationDestroyed(fn () => $this->cleanUpModules());
    }

    /**
     * Copies a fixture module in. Its migrations stay behind unless asked
     * for: they're DDL, which MySQL commits on the spot, so they escape
     * RefreshDatabase's transaction and need cleaning up by hand.
     */
    protected function addFixtureModule(string $name, bool $withMigrations = false): string
    {
        $path = $this->modulesPath.DIRECTORY_SEPARATOR.$name;
        File::copyDirectory(base_path("tests/Fixtures/modules/{$name}"), $path);

        if (! $withMigrations) {
            File::deleteDirectory($path.DIRECTORY_SEPARATOR.'database');
        }

        return $path;
    }

    /**
     * Loads enabled modules the way the next request would.
     */
    protected function bootModules(): ModuleLoader
    {
        $this->app->forgetInstance(ModuleLoader::class);
        $loader = $this->app->make(ModuleLoader::class);
        $loader->registerEnabled();
        $loader->bootLoaded();

        return $loader;
    }

    private function cleanUpModules(): void
    {
        foreach (glob($this->modulesPath.'/*/database/migrations') ?: [] as $migrations) {
            $this->app->make('migrator')->reset([$migrations]);
            Module::query()->delete();
        }

        $this->app->make(ModuleAutoloader::class)->unregister();
        File::deleteDirectory($this->modulesPath);
    }
}
