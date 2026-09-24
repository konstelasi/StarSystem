<?php

namespace App\Modules;

use App\Hooks\Hook;
use App\Models\Module;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleAutoloader::class);
        $this->app->singleton(ModuleManager::class);
        $this->app->singleton(ModuleLoader::class);
        $this->app->singleton(SafeMode::class);

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

        // Installing a module runs arbitrary PHP, so only the install's
        // owner may. There are no roles yet; the owner is the first account,
        // the one the installer creates. A roles feature can redefine this.
        Gate::define('manage-modules', fn (User $user) => $user->id === User::query()->min('id'));

        Inertia::share('moduleNotices', fn (Request $request) => $this->notices($request));
    }

    /**
     * What the admin notice shows: manual safe mode, and modules that were
     * switched off because they failed.
     *
     * @return array{safeMode: string|null, failed: list<array{slug: string, name: string, error: string|null}>}|null
     */
    private function notices(Request $request): ?array
    {
        if (! $request->user()?->can('manage-modules')) {
            return null;
        }

        return [
            'safeMode' => $this->app->make(SafeMode::class)->reason(),
            'failed' => array_values(Module::query()
                ->where('enabled', false)
                ->whereNotNull('last_error')
                ->orderBy('name')
                ->get()
                ->map(fn (Module $module) => [
                    'slug' => $module->slug,
                    'name' => $module->name,
                    'error' => $module->last_error,
                ])
                ->all()),
        ];
    }
}
