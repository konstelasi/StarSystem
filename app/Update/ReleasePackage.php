<?php

namespace App\Update;

use ZipArchive;

/**
 * A downloaded release zip: checked entry by entry before anything is
 * written, then unpacked in slices that each fit in one request.
 */
class ReleasePackage
{
    /**
     * The folder every entry sits under, as build/release.php writes it.
     */
    public const ROOT = 'starsystem';

    /**
     * Files without which the unpacked release couldn't run at all.
     */
    public const REQUIRED = ['VERSION', 'artisan', 'index.php', 'public/index.php', 'vendor/autoload.php', 'bootstrap/app.php'];

    public function __construct(private readonly string $path) {}

    /**
     * Refuse anything that could write outside the unpack folder or plant
     * a link: absolute paths, `..`, backslashes, other top-level folders and
     * symlinks.
     */
    public function validate(): void
    {
        $zip = $this->open();
        $names = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (! $this->safeName($name)) {
                    throw new UpdateException("The release contains a file StarSystem won't unpack: [{$name}].");
                }

                $zip->getExternalAttributesIndex($i, $system, $attributes);

                if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new UpdateException("The release contains a symbolic link, which StarSystem won't unpack: [{$name}].");
                }

                $names[$name] = true;
            }
        } finally {
            $zip->close();
        }

        foreach (self::REQUIRED as $required) {
            if (! isset($names[self::ROOT.'/'.$required])) {
                throw new UpdateException("The release is incomplete: [{$required}] is missing.");
            }
        }
    }

    /**
     * Unpack entries from $from on, stopping once $seconds have passed.
     * Returns where to carry on, or null once everything is unpacked.
     */
    public function extract(string $target, int $from = 0, float $seconds = 10.0): ?int
    {
        $zip = $this->open();
        $deadline = microtime(true) + $seconds;

        try {
            for ($i = $from; $i < $zip->numFiles; $i++) {
                if ($i > $from && microtime(true) >= $deadline) {
                    return $i;
                }

                $name = (string) $zip->getNameIndex($i);

                // Checked again here: the zip on disk may have changed
                // since validate() read it.
                if (! $this->safeName($name) || ! $zip->extractTo($target, $name)) {
                    throw new UpdateException("Couldn't unpack [{$name}] from the release.");
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Problems that would stop the release from running on this server,
     * read from Composer's platform check inside the unpacked release.
     *
     * @return list<string>
     */
    public static function platformProblems(string $unpacked): array
    {
        $check = @file_get_contents($unpacked.'/vendor/composer/platform_check.php');

        if ($check === false) {
            return [];
        }

        $problems = [];

        if (preg_match('/PHP_VERSION_ID >= (\d+)/', $check, $match) === 1 && (int) $match[1] > PHP_VERSION_ID) {
            $needed = (int) $match[1];
            $problems[] = sprintf(
                'The new version needs PHP %d.%d.%d or newer; this server runs PHP %s.',
                intdiv($needed, 10000), intdiv($needed % 10000, 100), $needed % 100, PHP_VERSION,
            );
        }

        preg_match_all("/extension_loaded\('([^']+)'\)/", $check, $matches);

        foreach (array_unique($matches[1]) as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = "The new version needs the PHP extension [{$extension}], which this server doesn't have.";
            }
        }

        return $problems;
    }

    private function safeName(string $name): bool
    {
        if (! str_starts_with($name, self::ROOT.'/') || str_contains($name, '\\') || str_contains($name, "\0") || str_contains($name, ':')) {
            return false;
        }

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function open(): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new UpdateException('The downloaded release isn\'t a readable zip file.');
        }

        return $zip;
    }
}
