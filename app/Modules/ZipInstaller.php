<?php

namespace App\Modules;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Installs or upgrades a module from an uploaded zip.
 *
 * The zip comes from a browser, so every entry is checked before anything
 * is written, and nothing is written outside a staging folder until the
 * whole zip has passed:
 * - no absolute paths, drive letters or `..` segments (zip-slip), no
 *   symlinks, and every file inside the zip's one top-level folder;
 * - hidden files (.htaccess, .user.ini) and macOS __MACOSX clutter are
 *   left out, since they can change how the server treats a folder;
 * - limits on the file count and the unpacked size, checked while
 *   writing too, since a zip can lie about sizes;
 * - a valid module.json that fits this StarSystem version.
 *
 * ZipArchive::extractTo() isn't used: each file is written by hand to a
 * path built from checked segments. Entries become plain files, never
 * links, whatever the zip says.
 *
 * A module whose folder already exists is only replaced by a newer version
 * of the same module. The new files are staged in full, then swapped in
 * with renames, so a failed upload never leaves a half-written module.
 */
class ZipInstaller
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly SafeMode $safeMode,
    ) {}

    public static function supported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @return array{manifest: Manifest, upgradedFrom: string|null}
     *
     * @throws ModuleException
     */
    public function install(string $zipPath): array
    {
        if (! self::supported()) {
            throw new ModuleException("This server's PHP has no zip support. Unzip the module and upload its folder into modules/ over FTP instead.");
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ModuleException("That file isn't a zip file.");
        }

        try {
            [$top, $files] = $this->entries($zip);
            $manifest = $this->manifest($zip, $top);
            $previous = $this->checkTarget($manifest);

            $this->write($zip, $files, $manifest);
        } finally {
            $zip->close();
        }

        $manifest = $this->modules->read($manifest->name);

        // In safe mode the upgrade waits, shown as ready in the module list.
        if ($previous !== null && ! $this->safeMode->isOn()) {
            $this->modules->applyUpdate($manifest->slug);
        }

        return ['manifest' => $manifest, 'upgradedFrom' => $previous];
    }

    /**
     * Checks every entry and returns the top-level folder and the files to
     * extract, as index => path inside that folder.
     *
     * @return array{string, array<int, string>}
     */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles === 0) {
            throw new ModuleException('The zip is empty.');
        }

        if ($zip->numFiles > config()->integer('modules.max_files')) {
            throw new ModuleException('The zip has more than '.config()->integer('modules.max_files').' files.');
        }

        $top = null;
        $files = [];
        $size = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new ModuleException("The zip is damaged: entry {$i} can't be read.");
            }

            $name = $stat['name'];
            $segments = $this->segments($name);

            if ($this->isSymlink($zip, $i)) {
                throw new ModuleException("The zip contains a symbolic link ({$name}). Modules can't contain links.");
            }

            // Hidden files like .htaccess or .user.ini change how the server
            // treats a folder; a module has no business shipping them.
            // __MACOSX is clutter from the macOS zip tool.
            if ($segments[0] === '__MACOSX' || collect($segments)->contains(fn (string $segment) => str_starts_with($segment, '.'))) {
                continue;
            }

            $top ??= $segments[0];
            $isDirectory = str_ends_with(str_replace('\\', '/', $name), '/');

            if ($segments[0] !== $top || (count($segments) === 1 && ! $isDirectory)) {
                throw new ModuleException("Everything in the zip must be inside one module folder, but {$name} is outside {$top}/.");
            }

            $size += (int) $stat['size'];

            if ($size > $this->maxBytes()) {
                throw new ModuleException('The module is larger than '.config()->integer('modules.max_unpacked_mb').' MB unpacked.');
            }

            if ($isDirectory) {
                continue;
            }

            $inner = array_slice($segments, 1);
            $files[$i] = implode('/', $inner);
        }

        if ($top === null) {
            throw new ModuleException('The zip has no module folder in it.');
        }

        return [$top, $files];
    }

    /**
     * An entry's path as checked segments.
     *
     * @return list<string>
     */
    private function segments(string $name): array
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ModuleException('The zip contains a file with an unreadable name.');
        }

        $path = str_replace('\\', '/', $name);

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new ModuleException("The zip contains an absolute path ({$name}). Files must stay inside the module folder.");
        }

        $segments = explode('/', rtrim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                throw new ModuleException("The zip contains a path that leads outside the module folder ({$name}).");
            }
        }

        return $segments;
    }

    /**
     * Unix zips keep the file mode in the upper 16 bits of the external
     * attributes; S_IFLNK marks a symlink.
     */
    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $system = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $system, $attributes) || $system !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === 0xA000;
    }

    private function manifest(ZipArchive $zip, string $top): Manifest
    {
        $json = $zip->getFromName($top.'/'.Manifest::FILE);

        if ($json === false) {
            throw new ModuleException("The zip has no {$top}/".Manifest::FILE.'. A module needs one at the top of its folder.');
        }

        try {
            $manifest = Manifest::fromJson($json, $this->modules->path('_'));
        } catch (InvalidManifest $e) {
            throw new InvalidManifest("The zip's module.json can't be used: {$e->getMessage()}", previous: $e);
        }

        if (! $this->modules->isCompatible($manifest)) {
            throw new ModuleException($this->modules->incompatibility($manifest));
        }

        return $manifest;
    }

    /**
     * Refuses to overwrite anything but an older copy of the same module.
     *
     * @return string|null The version being upgraded from.
     */
    private function checkTarget(Manifest $manifest): ?string
    {
        foreach ($this->modules->discover() as $folder => $existing) {
            if ($existing instanceof Manifest && $existing->slug === $manifest->slug && $folder !== $manifest->name) {
                throw new ModuleException("The module {$manifest->slug} is already installed in the {$folder} folder.");
            }
        }

        if (! file_exists($this->modules->path($manifest->name))) {
            return null;
        }

        try {
            $existing = $this->modules->read($manifest->name);
        } catch (InvalidManifest) {
            throw new ModuleException("A folder named {$manifest->name} is already in modules/ and isn't a working module. Remove it over FTP first.");
        }

        if ($existing->slug !== $manifest->slug) {
            throw new ModuleException("The {$manifest->name} folder already holds a different module, {$existing->slug}.");
        }

        if (! version_compare($manifest->version, $existing->version, '>')) {
            throw new ModuleException("{$manifest->name} {$existing->version} is already installed. Upload a newer version to upgrade it.");
        }

        return $existing->version;
    }

    /**
     * @param  array<int, string>  $files
     */
    private function write(ZipArchive $zip, array $files, Manifest $manifest): void
    {
        $staging = $this->modules->path('.staging-'.Str::random(12));
        $target = $this->modules->path($manifest->name);
        $old = $this->modules->path('.old-'.Str::random(12));

        File::ensureDirectoryExists($staging.DIRECTORY_SEPARATOR.$manifest->name);
        $root = (string) realpath($staging.DIRECTORY_SEPARATOR.$manifest->name);

        try {
            $written = 0;

            foreach ($files as $index => $path) {
                $written += $this->extract($zip, $index, $root, $path, $this->maxBytes() - $written);
            }

            if (is_dir($target)) {
                $this->move($target, $old);
            }

            try {
                $this->move($root, $target);
            } catch (Throwable $e) {
                if (is_dir($old)) {
                    $this->move($old, $target);
                }

                throw $e;
            }
        } finally {
            File::deleteDirectory($staging);
            File::deleteDirectory($old);
        }
    }

    /**
     * Writes one entry and returns the bytes written.
     */
    private function extract(ZipArchive $zip, int $index, string $root, string $path, int $budget): int
    {
        $destination = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        File::ensureDirectoryExists(dirname($destination));

        // Belt and braces: the segments were checked, but make sure the
        // folder really is inside the staging folder before writing.
        $directory = realpath(dirname($destination));

        if ($directory === false || ($directory !== $root && ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR))) {
            throw new ModuleException("The zip contains a path that leads outside the module folder ({$path}).");
        }

        $in = $zip->getStreamIndex($index);
        $out = fopen($destination, 'wb');

        if ($in === false || $out === false) {
            throw new ModuleException("Couldn't unpack {$path}. Check that modules/ is writable.");
        }

        try {
            $written = 0;

            while (! feof($in)) {
                $chunk = (string) fread($in, 65536);
                $written += strlen($chunk);

                if ($written > $budget) {
                    throw new ModuleException('The module is larger than '.config()->integer('modules.max_unpacked_mb').' MB unpacked.');
                }

                fwrite($out, $chunk);
            }

            return $written;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new ModuleException("Couldn't move the module into place. Check that modules/ is writable.");
        }
    }

    private function maxBytes(): int
    {
        return config()->integer('modules.max_unpacked_mb') * 1024 * 1024;
    }
}
