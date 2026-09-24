<?php

namespace App\Modules;

use App\Models\Module;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Finds modules in the modules folder and changes their state.
 *
 * Module migrations live in `<Module>/database/migrations/` and run when
 * the module is enabled, and again when an upgrade is applied; the
 * migrations table remembers which have run. Disabling keeps a module's
 * tables. Name migration files and tables after the slug
 * (`2026_01_01_000000_create_mod_example_notes_table.php`), because every
 * migration shares the one migrations table.
 */
class ModuleManager
{
    public function __construct(
        private readonly ModuleAutoloader $autoloader,
        private readonly Container $container,
    ) {}

    public function path(string $name = ''): string
    {
        $root = rtrim(config()->string('modules.path'), '/\\');

        return $name === '' ? $root : $root.DIRECTORY_SEPARATOR.$name;
    }

    public function coreVersion(): string
    {
        return config()->string('modules.core_version');
    }

    /**
     * Every folder in the modules folder, with its manifest or what's wrong
     * with it.
     *
     * @return array<string, Manifest|InvalidManifest> By folder name.
     */
    public function discover(): array
    {
        $found = [];

        foreach (is_dir($this->path()) ? (scandir($this->path()) ?: []) : [] as $folder) {
            // Dot folders are staging areas and editor clutter, not modules.
            if (str_starts_with($folder, '.') || ! is_dir($this->path($folder))) {
                continue;
            }

            try {
                $found[$folder] = $this->read($folder);
            } catch (InvalidManifest $e) {
                $found[$folder] = $e;
            }
        }

        return $found;
    }

    /**
     * @throws InvalidManifest
     */
    public function read(string $folder): Manifest
    {
        $manifest = Manifest::load($this->path($folder));

        if ($manifest->name !== $folder) {
            throw new InvalidManifest("The folder is named {$folder}, but module.json says the module is {$manifest->name}. Rename one to match.");
        }

        return $manifest;
    }

    /**
     * @throws ModuleException
     */
    public function find(string $slug): Manifest
    {
        foreach ($this->discover() as $manifest) {
            if ($manifest instanceof Manifest && $manifest->slug === $slug) {
                return $manifest;
            }
        }

        throw new ModuleException("There's no module \"{$slug}\" in the modules folder.");
    }

    public function isCompatible(Manifest $manifest): bool
    {
        return VersionConstraint::satisfies($this->coreVersion(), $manifest->requires);
    }

    public function incompatibility(Manifest $manifest): string
    {
        return "{$manifest->name} {$manifest->version} needs StarSystem {$manifest->requires}, and this is {$this->coreVersion()}.";
    }

    /**
     * Checks the module can load, runs its migrations and switches it on.
     * Its provider is registered from the next request.
     *
     * @throws ModuleException
     */
    public function enable(string $slug): Module
    {
        $manifest = $this->find($slug);

        if (! $this->isCompatible($manifest)) {
            throw new ModuleException($this->incompatibility($manifest));
        }

        $this->autoloader->add($manifest->namespace, $manifest->sourcePath());

        try {
            $loads = class_exists($manifest->provider);
        } catch (Throwable $e) {
            throw new ModuleException("{$manifest->name}'s provider has an error: {$e->getMessage()}", previous: $e);
        }

        if (! $loads) {
            throw new ModuleException("{$manifest->name}'s provider {$manifest->provider} isn't in its src/ folder.");
        }

        $this->migrate($manifest);

        $module = Module::firstOrNew(['slug' => $manifest->slug]);
        $module->fill([
            'name' => $manifest->name,
            'version' => $manifest->version,
            'enabled' => true,
            'last_error' => null,
            'last_error_at' => null,
        ]);
        $module->installed_at ??= now()->toImmutable();
        $module->save();

        return $module;
    }

    public function disable(string $slug): void
    {
        Module::query()->where('slug', $slug)->update(['enabled' => false]);
    }

    /**
     * Runs the module's migrations that haven't run yet.
     *
     * @throws ModuleException
     */
    public function migrate(Manifest $manifest): void
    {
        if (! is_dir($manifest->migrationsPath())) {
            return;
        }

        $migrator = $this->migrator();

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        try {
            $migrator->run([$manifest->migrationsPath()]);
        } catch (Throwable $e) {
            throw new ModuleException("{$manifest->name}'s migrations failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Resolved when needed: the manager is built while providers register,
     * before Laravel's deferred migration services can be resolved.
     */
    private function migrator(): Migrator
    {
        return $this->container->make('migrator');
    }
}
