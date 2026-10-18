<?php

namespace App\Modules;

use App\Access\Ability;
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
 *         "assets": "dist",                  // optional, served at /_modules/example/…
 *         "abilities": {                     // optional, what a role can be given for this module
 *             "view": "See submissions"      // becomes the ability "example.view"
 *         }
 *     }
 *
 * Classes under the namespace load from the module's src/ folder.
 */
final class Manifest
{
    public const FILE = 'module.json';

    /** Root namespaces a module can't claim, so it can't pose as core code. */
    private const RESERVED_NAMESPACES = ['App', 'Database', 'Tests', 'Illuminate', 'Laravel', 'StarDust', 'Inertia'];

    /** Most abilities one module may add, so a role's page stays readable. */
    private const MOST_ABILITIES = 20;

    /**
     * @param  array<string, string>  $abilities  The abilities the module adds, by short name, each with the label the Roles page shows.
     */
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
        public readonly array $abilities,
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
        $abilities = self::abilities($data['abilities'] ?? [], $slug);

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
            abilities: $abilities,
            path: $directory,
        );
    }

    /**
     * The module's abilities as they are named on the Roles page and in a
     * route's `can:`, "slug.name" with the label for each.
     *
     * @return array<string, string>
     */
    public function qualifiedAbilities(): array
    {
        $qualified = [];

        foreach ($this->abilities as $name => $label) {
            $qualified["{$this->slug}.{$name}"] = $label;
        }

        return $qualified;
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
     * @return array<string, string>
     *
     * @throws InvalidManifest
     */
    private static function abilities(mixed $declared, string $slug): array
    {
        if ($declared === [] || $declared === null) {
            return [];
        }

        if (! is_array($declared) || array_is_list($declared)) {
            throw new InvalidManifest('"abilities" must be an object of short names and labels, like {"view": "See submissions"}.');
        }

        if (count($declared) > self::MOST_ABILITIES) {
            throw new InvalidManifest('"abilities" can hold at most '.self::MOST_ABILITIES.'.');
        }

        // An ability is named after the module, so a module named like one
        // of StarSystem's own areas would speak for it.
        if (in_array($slug, Ability::areas(), true)) {
            throw new InvalidManifest("\"slug\" can't be \"{$slug}\" in a module that adds abilities, since that name belongs to StarSystem's own abilities.");
        }

        $abilities = [];

        foreach ($declared as $name => $label) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name)) {
                throw new InvalidManifest('An ability name must be lowercase letters, digits and underscores, like "view" or "export_all".');
            }

            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 120) {
                throw new InvalidManifest("The ability \"{$name}\" needs a label of up to 120 characters for the Roles page.");
            }

            $abilities[$name] = trim($label);
        }

        return $abilities;
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
