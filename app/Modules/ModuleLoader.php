<?php

namespace App\Modules;

use App\Hooks\HookRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use stdClass;
use Throwable;

/**
 * Registers and boots enabled modules' providers on every request.
 *
 * The loader registers and boots each provider itself rather than through
 * Application::register(), so each step runs inside the module's hook
 * ownership and one place controls what happens when a module misbehaves.
 */
class ModuleLoader
{
    /** @var array<string, array{manifest: Manifest, provider: ServiceProvider}> By slug. */
    private array $loaded = [];

    public function __construct(
        private readonly Application $app,
        private readonly ModuleManager $modules,
        private readonly ModuleAutoloader $autoloader,
        private readonly HookRegistry $hooks,
    ) {}

    public function registerEnabled(): void
    {
        foreach ($this->enabled() as $row) {
            $manifest = $this->manifestFor($row->slug, $row->name);

            if ($manifest === null) {
                continue;
            }

            $this->autoloader->add($manifest->namespace, $manifest->sourcePath());

            $provider = $this->makeProvider($manifest);
            $this->hooks->asOwner($manifest->slug, fn () => $this->register($provider));

            $this->loaded[$manifest->slug] = ['manifest' => $manifest, 'provider' => $provider];
        }
    }

    public function bootLoaded(): void
    {
        foreach ($this->loaded as $slug => ['provider' => $provider]) {
            $this->hooks->asOwner($slug, fn () => $this->boot($provider));
        }
    }

    /**
     * @return array<string, Manifest> The modules running in this request, by slug.
     */
    public function loaded(): array
    {
        return array_map(fn (array $module) => $module['manifest'], $this->loaded);
    }

    /**
     * Enabled modules from the database. This runs while the app registers
     * its providers, before Eloquent is set up, and before the database
     * exists at all on a fresh install, so it uses the query builder and
     * treats any failure as "no modules".
     *
     * @return Collection<int, stdClass>
     */
    private function enabled(): Collection
    {
        try {
            return $this->app->make('db')->table('ss_modules')
                ->where('enabled', true)
                ->orderBy('id')
                ->get(['slug', 'name']);
        } catch (Throwable) {
            return new Collection;
        }
    }

    private function manifestFor(string $slug, string $name): ?Manifest
    {
        try {
            $manifest = $this->modules->read($name);
        } catch (InvalidManifest $e) {
            Log::warning("Module {$name} is enabled but can't load: {$e->getMessage()}");

            return null;
        }

        if ($manifest->slug !== $slug) {
            Log::warning("Module folder {$name} now holds {$manifest->slug}, not {$slug}.");

            return null;
        }

        // A StarSystem upgrade can leave an enabled module behind.
        if (! $this->modules->isCompatible($manifest)) {
            Log::warning($this->modules->incompatibility($manifest));

            return null;
        }

        return $manifest;
    }

    private function makeProvider(Manifest $manifest): ServiceProvider
    {
        $provider = new ($manifest->provider)($this->app);

        if (! $provider instanceof ServiceProvider) {
            throw new ModuleException("{$manifest->provider} isn't a service provider.");
        }

        if ($provider instanceof ModuleServiceProvider) {
            $provider->setManifest($manifest);
        }

        return $provider;
    }

    /**
     * What Application::register() does for a provider, minus marking it
     * registered (that's protected).
     */
    private function register(ServiceProvider $provider): void
    {
        $provider->register();

        foreach (property_exists($provider, 'bindings') ? $provider->bindings : [] as $key => $value) {
            $this->app->bind($key, $value);
        }

        foreach (property_exists($provider, 'singletons') ? $provider->singletons : [] as $key => $value) {
            $this->app->singleton(is_int($key) ? $value : $key, $value);
        }
    }

    /**
     * What Application::bootProvider() does.
     */
    private function boot(ServiceProvider $provider): void
    {
        $provider->callBootingCallbacks();

        if (method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }

        $provider->callBootedCallbacks();
    }
}
