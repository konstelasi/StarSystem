<?php

namespace App\Install;

use Carbon\CarbonImmutable;

/**
 * Whether this copy of StarSystem is installed, and the files that say so.
 *
 * Installed means the installer's marker exists or APP_KEY is set. Either
 * one closes the installer for good: a developer install made with
 * `composer setup` has a key but no marker, and a host that loses .env
 * still has the marker. An open installer would let anyone who finds it
 * create an administrator, so it only opens when both are missing.
 */
class Installation
{
    private readonly string $markerPath;

    private readonly string $envPath;

    public function __construct(?string $markerPath = null, ?string $envPath = null)
    {
        $this->markerPath = $markerPath ?? storage_path('app/installed');
        $this->envPath = $envPath ?? app()->environmentFilePath();
    }

    public function installed(): bool
    {
        return is_file($this->markerPath) || (string) config('app.key') !== '';
    }

    public function markerPath(): string
    {
        return $this->markerPath;
    }

    public function env(): EnvironmentFile
    {
        return new EnvironmentFile($this->envPath, base_path('.env.example'));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function markInstalled(array $details = []): void
    {
        if (! is_dir(dirname($this->markerPath))) {
            mkdir(dirname($this->markerPath), 0775, true);
        }

        file_put_contents($this->markerPath, json_encode([
            'installed_at' => CarbonImmutable::now()->toIso8601String(),
            ...$details,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
