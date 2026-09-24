<?php

namespace App\Modules;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safe mode, kept from HyperCMS: a broken module must never take the whole
 * site down, because on shared hosting there's no shell to fix it from.
 *
 * Automatic: when a module's provider throws while registering or booting
 * (or its files are gone or no longer fit this StarSystem version), the
 * loader switches that module off, records why in ss_modules.last_error,
 * and the request carries on without it. Admins see a notice.
 *
 * Manual: with STARSYSTEM_SAFE_MODE=true in .env, or a file named
 * `safe-mode` in storage/, no module loads at all. That covers what can't
 * be caught, like a fatal error or a module that exhausts memory.
 *
 * Recovering without a shell:
 * 1. Over FTP or the host's file manager, create an empty file named
 *    `safe-mode` in the storage/ folder. The site comes back without
 *    modules.
 * 2. Switch off the module that broke things: delete its folder from
 *    modules/ (an enabled module whose files are gone is switched off on
 *    the next request), or run
 *    `UPDATE ss_modules SET enabled = 0 WHERE slug = '…'` in phpMyAdmin.
 * 3. Delete storage/safe-mode.
 */
class SafeMode
{
    /** @var array<string, string> Modules switched off in this request, slug => why. */
    private array $failures = [];

    public function __construct(private readonly Container $container) {}

    public function isOn(): bool
    {
        return $this->reason() !== null;
    }

    /**
     * Why manual safe mode is on: 'env', 'file', or null when it's off.
     */
    public function reason(): ?string
    {
        if (config('modules.safe_mode')) {
            return 'env';
        }

        return is_file($this->flagFile()) ? 'file' : null;
    }

    public function flagFile(): string
    {
        return config()->string('modules.safe_mode_file');
    }

    /**
     * Switches a module off because it failed, and records why.
     */
    public function switchOff(string $slug, string $why, ?Throwable $e = null): void
    {
        $this->failures[$slug] = $why;

        Log::error("Module {$slug} was switched off: {$why}", $e ? ['exception' => $e] : []);

        // Runs while providers register, before Eloquent is ready, so this
        // uses the query builder. If even that fails, the module still
        // stays off for this request.
        try {
            $this->container->make('db')->table('ss_modules')->where('slug', $slug)->update([
                'enabled' => false,
                'last_error' => mb_substr($why, 0, 60000),
                'last_error_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, string> slug => why
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public static function describe(Throwable $e): string
    {
        return get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
    }
}
