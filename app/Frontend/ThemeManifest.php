<?php

namespace App\Frontend;

use App\Modules\VersionConstraint;
use JsonException;

/**
 * A theme's theme.json, checked. Anything a theme could get wrong is
 * reported here with a message an admin can act on, before any of its
 * templates run.
 *
 *     {
 *         "name": "Default",
 *         "version": "1.0.0",
 *         "description": "A plain starter theme.",
 *         "author": "Someone",
 *         "requires": { "starsystem": "^0.1" }
 *     }
 */
final class ThemeManifest
{
    public const FILE = 'theme.json';

    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly string $requires,
    ) {}

    /**
     * Reads the manifest in $directory.
     *
     * @throws InvalidThemeManifest
     */
    public static function load(string $directory): self
    {
        $file = $directory.DIRECTORY_SEPARATOR.self::FILE;

        if (! is_file($file)) {
            throw new InvalidThemeManifest('The theme has no '.self::FILE.'.');
        }

        return self::fromJson((string) file_get_contents($file));
    }

    /**
     * @throws InvalidThemeManifest
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidThemeManifest(self::FILE." isn't valid JSON: {$e->getMessage()}.");
        }

        if (! is_array($data)) {
            throw new InvalidThemeManifest(self::FILE.' must be a JSON object.');
        }

        $name = self::string($data, 'name');
        $version = self::string($data, 'version');
        $requires = is_array($data['requires'] ?? null) ? ($data['requires']['starsystem'] ?? null) : null;

        if (mb_strlen($name) > 100) {
            throw new InvalidThemeManifest('"name" can be up to 100 characters.');
        }

        if (! VersionConstraint::isVersion($version)) {
            throw new InvalidThemeManifest('"version" must be a version like 1.2.3.');
        }

        if (! is_string($requires) || ! VersionConstraint::isValid($requires)) {
            throw new InvalidThemeManifest('"requires.starsystem" must be a version constraint like "^0.1".');
        }

        return new self(
            name: $name,
            version: $version,
            description: is_string($data['description'] ?? null) ? trim($data['description']) : '',
            author: is_string($data['author'] ?? null) ? trim($data['author']) : '',
            requires: $requires,
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidThemeManifest("\"{$key}\" is missing from ".self::FILE.'.');
        }

        return trim($value);
    }
}
