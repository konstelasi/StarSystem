<?php

namespace App\Modules;

use App\Models\Module;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
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
        $this->refuseInSafeMode();

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
     * Brings an enabled module's database up to its files after an upgrade,
     * whether uploaded as a zip or copied in over FTP. A module that isn't
     * enabled catches up when it is.
     *
     * @throws ModuleException
     */
    public function applyUpdate(string $slug): void
    {
        $module = Module::query()->where('slug', $slug)->where('enabled', true)->first();

        if ($module === null) {
            return;
        }

        $this->refuseInSafeMode();

        $manifest = $this->find($slug);

        try {
            $this->migrate($manifest);
        } catch (ModuleException $e) {
            $module->update(['enabled' => false, 'last_error' => $e->getMessage(), 'last_error_at' => now()]);

            throw new ModuleException($e->getMessage().' The module was switched off.', previous: $e);
        }

        $module->update(['name' => $manifest->name, 'version' => $manifest->version]);
    }

    /**
     * Removes a module's files and its row. With $deleteData, first rolls
     * back its migrations, which drops its tables.
     *
     * @throws ModuleException
     */
    public function uninstall(string $slug, bool $deleteData): void
    {
        try {
            $manifest = $this->find($slug);
        } catch (ModuleException) {
            // Its folder is already gone; only the row is left to remove.
            Module::query()->where('slug', $slug)->delete();

            return;
        }

        if ($deleteData && is_dir($manifest->migrationsPath())) {
            // Rolling back runs the module's own migration code.
            $this->refuseInSafeMode();

            try {
                $this->migrator()->reset([$manifest->migrationsPath()]);
            } catch (Throwable $e) {
                throw new ModuleException("{$manifest->name}'s data couldn't be removed, so it's still installed: {$e->getMessage()}", previous: $e);
            }
        }

        Module::query()->where('slug', $slug)->delete();

        $this->autoloader->remove($manifest->namespace);

        // deleteDirectory() doesn't follow symlinks, so a link inside the
        // module can't take files outside it along.
        if (! File::deleteDirectory($manifest->path)) {
            throw new ModuleException("{$manifest->name} was switched off, but its folder couldn't be deleted. Delete modules/{$manifest->name} over FTP.");
        }
    }

    public function dismissError(string $slug): void
    {
        Module::query()->where('slug', $slug)->update(['last_error' => null, 'last_error_at' => null]);
    }

    /**
     * Everything the admin's module list shows: every folder in modules/,
     * and every module the database knows whose folder is gone.
     *
     * @return list<array{slug: string|null, name: string, version: string|null, installedVersion: string|null, description: string, author: string, requires: string|null, enabled: bool, updatePending: bool, problem: string|null, lastError: string|null, lastErrorAt: string|null}>
     */
    public function overview(): array
    {
        $rows = Module::query()->get()->keyBy('slug');
        $list = [];

        foreach ($this->discover() as $folder => $manifest) {
            if ($manifest instanceof InvalidManifest) {
                $list[] = $this->entry(null, $folder, null, $manifest->getMessage());

                continue;
            }

            $row = $rows->pull($manifest->slug);

            $list[] = $this->entry($manifest, $manifest->name, $row, $this->isCompatible($manifest) ? null : $this->incompatibility($manifest));
        }

        foreach ($rows as $row) {
            $list[] = $this->entry(null, $row->name, $row, "Its folder, modules/{$row->name}, is missing.");
        }

        return $list;
    }

    /**
     * @return array{slug: string|null, name: string, version: string|null, installedVersion: string|null, description: string, author: string, requires: string|null, enabled: bool, updatePending: bool, problem: string|null, lastError: string|null, lastErrorAt: string|null}
     */
    private function entry(?Manifest $manifest, string $name, ?Module $row, ?string $problem): array
    {
        return [
            'slug' => $manifest->slug ?? $row?->slug,
            'name' => $name,
            'version' => $manifest?->version,
            'installedVersion' => $row?->version,
            'description' => $manifest->description ?? '',
            'author' => $manifest->author ?? '',
            'requires' => $manifest?->requires,
            'enabled' => (bool) $row?->enabled,
            'updatePending' => $manifest !== null && $row !== null && $row->enabled && $row->version !== $manifest->version,
            'problem' => $problem,
            'lastError' => $row?->last_error,
            'lastErrorAt' => $row?->last_error_at?->toIso8601String(),
        ];
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
     * Enabling or updating runs the module's code (its provider loads, its
     * migrations run), which manual safe mode promises not to do.
     *
     * @throws ModuleException
     */
    private function refuseInSafeMode(): void
    {
        if ($this->container->make(SafeMode::class)->isOn()) {
            throw new ModuleException('Safe mode is on, so no module code may run. Turn safe mode off first.');
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
