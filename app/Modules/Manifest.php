<?php

namespace App\Modules;

use JsonException;

/**
 * A module's module.json, checked. Anything a module could get wrong is
 * reported here with a message an admin can act on, before any of its
 * PHP runs.
 *
 *     {
 *         "name": "Example",                 // folder name, StudlyCase
 *         "slug": "example",                 // identity in URLs, the database and hook names
 *         "version": "1.0.0",
 *         "description": "What it does.",
 *         "author": "Someone",
 *         "requires": { "starsystem": "^0.1" },
 *         "namespace": "Modules\\Example",   // optional, this is the default
 *         "provider": "Modules\\Example\\ExampleServiceProvider",
 *         "assets": "dist"                   // optional, served at /_modules/example/…
 *     }
 *
 * Classes under the namespace load from the module's src/ folder.
 */
final class Manifest
{
    public const FILE = 'module.json';

    /** Root namespaces a module can't claim, so it can't pose as core code. */
    private const RESERVED_NAMESPACES = ['App', 'Database', 'Tests', 'Illuminate', 'Laravel', 'StarDust', 'Inertia'];

    private function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly string $requires,
        public readonly string $namespace,
        public readonly string $provider,
        public readonly ?string $assets,
        public readonly string $path,
    ) {}

    /**
     * Reads the manifest in $directory.
     *
     * @throws InvalidManifest
     */
    public static function load(string $directory): self
    {
        $file = $directory.DIRECTORY_SEPARATOR.self::FILE;

        if (! is_file($file)) {
            throw new InvalidManifest('The module has no '.self::FILE.'.');
        }

        return self::fromJson((string) file_get_contents($file), $directory);
    }

    /**
     * @throws InvalidManifest
     */
    public static function fromJson(string $json, string $directory): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidManifest(self::FILE." isn't valid JSON: {$e->getMessage()}.");
        }

        if (! is_array($data)) {
            throw new InvalidManifest(self::FILE.' must be a JSON object.');
        }

        return self::fromArray($data, $directory);
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidManifest
     */
    public static function fromArray(array $data, string $directory): self
    {
        $name = self::string($data, 'name');
        $slug = self::string($data, 'slug');
        $version = self::string($data, 'version');
        $provider = self::string($data, 'provider');
        $requires = is_array($data['requires'] ?? null) ? ($data['requires']['starsystem'] ?? null) : null;
        $namespace = $data['namespace'] ?? "Modules\\{$name}";
        $assets = $data['assets'] ?? null;

        if (! preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/', $name)) {
            throw new InvalidManifest('"name" must be the module\'s folder name in StudlyCase, like "PagingSystem".');
        }

        if (! preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 64) {
            throw new InvalidManifest('"slug" must be lowercase letters, digits and dashes, like "paging-system".');
        }

        if (! VersionConstraint::isVersion($version)) {
            throw new InvalidManifest('"version" must be a version like 1.2.3.');
        }

        if (! is_string($requires) || ! VersionConstraint::isValid($requires)) {
            throw new InvalidManifest('"requires.starsystem" must be a version constraint like "^0.1".');
        }

        if (! is_string($namespace) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', trim($namespace, '\\'))) {
            throw new InvalidManifest('"namespace" must be a PHP namespace like "Modules\\\\Example".');
        }

        $namespace = trim($namespace, '\\');
        $provider = ltrim($provider, '\\');

        if (in_array(explode('\\', $namespace)[0], self::RESERVED_NAMESPACES, true)) {
            throw new InvalidManifest("\"namespace\" can't be {$namespace}, which belongs to StarSystem or its libraries.");
        }

        // The provider must load from the module's own src/, not from the
        // app or another module.
        if (! str_starts_with($provider, $namespace.'\\')) {
            throw new InvalidManifest("\"provider\" must be a class in the module's namespace, {$namespace}.");
        }

        if ($assets !== null && (! is_string($assets) || ! self::isRelativePath($assets))) {
            throw new InvalidManifest('"assets" must be a folder inside the module, like "dist".');
        }

        return new self(
            name: $name,
            slug: $slug,
            version: $version,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            author: is_string($data['author'] ?? null) ? $data['author'] : '',
            requires: $requires,
            namespace: $namespace,
            provider: $provider,
            assets: $assets === null ? null : trim($assets, '/'),
            path: $directory,
        );
    }

    public function sourcePath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'src';
    }

    /**
     * Module migrations live here and run when the module is enabled.
     */
    public function migrationsPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }

    public function assetsPath(): ?string
    {
        return $this->assets === null ? null : $this->path.DIRECTORY_SEPARATOR.$this->assets;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidManifest("\"{$key}\" is missing from ".self::FILE.'.');
        }

        return trim($value);
    }

    private static function isRelativePath(string $path): bool
    {
        $path = trim($path, '/');

        return $path !== ''
            && ! str_contains($path, '\\')
            && ! preg_match('/^[A-Za-z]:/', $path)
            && ! in_array('..', explode('/', $path), true);
    }
}
