<?php

namespace App\Modules;

/**
 * PSR-4 class loading for modules, without Composer. Consumers install
 * modules by uploading a zip on hosts where Composer can't run, so the
 * vendor autoloader never learns about them. Each module maps its
 * manifest's namespace to its src/ folder here instead.
 */
class ModuleAutoloader
{
    /** @var array<string, string> Namespace prefix (with trailing \) => directory. */
    private array $prefixes = [];

    private bool $registered = false;

    public function add(string $namespace, string $directory): void
    {
        $this->prefixes[trim($namespace, '\\').'\\'] = rtrim($directory, '/\\');

        // Longest prefix first, so a module can't shadow a deeper namespace
        // that belongs to another one.
        uksort($this->prefixes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        if (! $this->registered) {
            spl_autoload_register([$this, 'load']);
            $this->registered = true;
        }
    }

    public function remove(string $namespace): void
    {
        unset($this->prefixes[trim($namespace, '\\').'\\']);
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $directory) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $file = $directory.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }

    public function unregister(): void
    {
        if ($this->registered) {
            spl_autoload_unregister([$this, 'load']);
            $this->registered = false;
        }
    }
}
