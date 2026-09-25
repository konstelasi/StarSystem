<?php

namespace App\Install;

use Closure;

/**
 * What the host must provide before anything is installed. Every failed
 * check says what to change in words a site owner can take to their
 * host's control panel or support desk.
 */
class Requirements
{
    public const PHP_VERSION = '8.3.0';

    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'zip', 'tokenizer', 'ctype', 'json'];

    /** @var Closure(string): bool */
    private readonly Closure $extensionLoaded;

    /** @var Closure(string): bool */
    private readonly Closure $writable;

    /**
     * @param  (Closure(string): bool)|null  $extensionLoaded
     * @param  (Closure(string): bool)|null  $writable
     */
    public function __construct(
        private readonly Installation $installation,
        private readonly string $phpVersion = PHP_VERSION,
        ?Closure $extensionLoaded = null,
        ?Closure $writable = null,
    ) {
        $this->extensionLoaded = $extensionLoaded ?? extension_loaded(...);
        $this->writable = $writable ?? fn (string $path) => is_dir($path) && is_writable($path);
    }

    /**
     * @return list<array{key: string, label: string, ok: bool, help: ?string}>
     */
    public function checks(): array
    {
        $checks = [$this->check(
            'php',
            'PHP '.substr(self::PHP_VERSION, 0, 3).' or newer',
            version_compare($this->phpVersion, self::PHP_VERSION, '>='),
            "This server runs PHP {$this->phpVersion}. Choose PHP ".substr(self::PHP_VERSION, 0, 3).' or newer in your hosting control panel (often under "Select PHP Version" or "PHP Manager").',
        )];

        foreach (self::EXTENSIONS as $extension) {
            $checks[] = $this->check(
                "ext-{$extension}",
                "PHP extension \"{$extension}\"",
                ($this->extensionLoaded)($extension),
                "Turn on the \"{$extension}\" extension in your hosting control panel's PHP settings, or ask your host to enable it.",
            );
        }

        // The folders PHP writes while running: logs, sessions, compiled
        // views, StarDust's working files, and the framework's caches.
        $folders = [
            'storage' => ['storage', [
                storage_path(),
                storage_path('app'),
                storage_path('framework'),
                storage_path('framework/cache'),
                storage_path('framework/sessions'),
                storage_path('framework/views'),
                storage_path('logs'),
            ]],
            'bootstrap-cache' => ['bootstrap/cache', [base_path('bootstrap/cache')]],
        ];

        foreach ($folders as $key => [$name, $paths]) {
            $checks[] = $this->check(
                $key,
                "The \"{$name}\" folder can be written to",
                collect($paths)->every(fn (string $path) => ($this->writable)($path)),
                "Using your host's file manager, set the permissions of the \"{$name}\" folder and every folder inside it to 755 (or 775 if that isn't enough).",
            );
        }

        $env = $this->installation->env();

        $checks[] = $this->check(
            'env',
            'The settings file (.env) can be saved',
            $env->writable(),
            $env->exists()
                ? 'StarSystem keeps its settings in the ".env" file next to "artisan". Set that file\'s permissions to 644 (or 664 if that isn\'t enough).'
                : 'StarSystem keeps its settings in a ".env" file next to "artisan". Make that folder writable, or create an empty file called ".env" there and set its permissions to 644.',
        );

        return $checks;
    }

    public function pass(): bool
    {
        return collect($this->checks())->every(fn (array $check) => $check['ok']);
    }

    /**
     * @return array{key: string, label: string, ok: bool, help: ?string}
     */
    private function check(string $key, string $label, bool $ok, string $help): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'help' => $ok ? null : $help];
    }
}
