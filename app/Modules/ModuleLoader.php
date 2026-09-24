<?php

namespace App\Modules;

use App\Hooks\HookRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use stdClass;
use Throwable;

/**
 * Registers and boots enabled modules' providers on every request.
 *
 * The loader registers and boots each provider itself rather than through
 * Application::register(), so it can catch what a provider throws while
 * booting too; Laravel would boot it outside any try/catch of ours. A
 * module that throws is switched off (see SafeMode) and the app carries on.
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
        private readonly SafeMode $safeMode,
    ) {}

    public function registerEnabled(): void
    {
        if ($this->safeMode->isOn()) {
            return;
        }

        foreach ($this->enabled() as $row) {
            $manifest = $this->manifestFor($row->slug, $row->name);

            if ($manifest === null) {
                continue;
            }

            try {
                $this->autoloader->add($manifest->namespace, $manifest->sourcePath());

                $provider = $this->makeProvider($manifest);
                $this->hooks->asOwner($manifest->slug, fn () => $this->register($provider));
            } catch (Throwable $e) {
                $this->fail($manifest, 'Its provider failed while registering: '.SafeMode::describe($e), $e);

                continue;
            }

            $this->loaded[$manifest->slug] = ['manifest' => $manifest, 'provider' => $provider];
        }
    }

    public function bootLoaded(): void
    {
        foreach ($this->loaded as $slug => ['manifest' => $manifest, 'provider' => $provider]) {
            try {
                $this->hooks->asOwner($slug, fn () => $this->boot($provider));
            } catch (Throwable $e) {
                $this->fail($manifest, 'Its provider failed while booting: '.SafeMode::describe($e), $e);
            }
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
            $this->safeMode->switchOff($slug, "Its files can't be loaded: {$e->getMessage()}");

            return null;
        }

        if ($manifest->slug !== $slug) {
            $this->safeMode->switchOff($slug, "The {$name} folder now holds a different module, {$manifest->slug}.");

            return null;
        }

        // A StarSystem upgrade can leave an enabled module behind.
        if (! $this->modules->isCompatible($manifest)) {
            $this->safeMode->switchOff($slug, $this->modules->incompatibility($manifest));

            return null;
        }

        return $manifest;
    }

    /**
     * Takes back what a failing module added, as far as that's possible:
     * its hooks and its classes. Container bindings and routes it already
     * added stay for this request; from the next one it isn't loaded.
     */
    private function fail(Manifest $manifest, string $why, Throwable $e): void
    {
        $this->hooks->removeOwnedBy($manifest->slug);
        $this->autoloader->remove($manifest->namespace);
        unset($this->loaded[$manifest->slug]);

        $this->safeMode->switchOff($manifest->slug, $why, $e);
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
