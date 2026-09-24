<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds module zips for the installer tests, including hostile ones.
 */
trait BuildsModuleZips
{
    /** @var list<string> */
    private array $zips = [];

    /**
     * A zip of a fixture module under $folder, plus any extra entries
     * (name => contents), and with the manifest changed by $manifest.
     *
     * @param  array<string, string>  $extra
     * @param  array<string, mixed>  $manifest
     */
    protected function moduleZip(string $fixture = 'Example', string $folder = 'Example', array $extra = [], array $manifest = [], bool $withManifest = true): string
    {
        $zip = $this->openZip();
        $source = base_path("tests/Fixtures/modules/{$fixture}");

        foreach (File::allFiles($source) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if ($relative === 'module.json' || str_starts_with($relative, 'database/')) {
                continue;
            }

            $zip->addFile($file->getPathname(), "{$folder}/{$relative}");
        }

        if ($withManifest) {
            $data = [...(array) json_decode((string) file_get_contents("{$source}/module.json"), true), ...$manifest];
            $zip->addFromString("{$folder}/module.json", (string) json_encode($data));
        }

        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        return $this->closeZip($zip);
    }

    /**
     * A module zip with a symlink in it, as `zip --symlinks` would make.
     */
    protected function zipWithSymlink(): string
    {
        $path = $this->moduleZip();
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('Example/src/secrets', '/etc/passwd');
        $zip->setExternalAttributesName('Example/src/secrets', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();

        return $path;
    }

    private function openZip(): ZipArchive
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-module-'.Str::random(10).'.zip';
        $this->zips[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        return $zip;
    }

    private function closeZip(ZipArchive $zip): string
    {
        $path = $zip->filename;
        $zip->close();

        return $path;
    }

    protected function deleteZips(): void
    {
        foreach ($this->zips as $zip) {
            File::delete($zip);
        }
    }
}
