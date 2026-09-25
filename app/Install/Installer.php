<?php

namespace App\Install;

use App\Models\Site;
use App\Models\User;
use App\StarDust\StarDustService;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The install, as short steps that each fit in one web request.
 *
 * Progress is never stored separately: every step reads the real state
 * (the .env file, the migrations table, StarDust's tables), so a step
 * that died halfway is simply run again, and every step is safe to
 * repeat.
 *
 * APP_KEY is written last. Until then the installer stays open and the
 * rest of the app stays closed, so a half-finished install can't be
 * reached through any page except this one.
 */
class Installer
{
    public const OWNER_COOKIE = 'starsystem_install';

    public function __construct(
        private readonly Installation $installation,
        private readonly Migrator $migrator,
    ) {}

    /**
     * Save the database details and the settings that follow from where
     * the files are, then hand out the ownership token for later steps.
     */
    public function saveDatabase(DatabaseCredentials $credentials, string $appUrl): string
    {
        $artifacts = storage_path('app/stardust/artifacts');
        $pids = storage_path('app/stardust/pids');

        foreach ([$artifacts, $pids] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $this->installation->env()->set([
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($appUrl, '/'),
            ...$credentials->toEnv(),
            // StarDust defaults to the system temp folder, which shared
            // hosts may share between accounts or empty at any time.
            'STARDUST_ARTIFACT_DIR' => $artifacts,
            'STARDUST_PID_DIR' => $pids,
        ]);

        $this->forgetCachedConfig();

        return $this->claim();
    }

    /**
     * The database details saved by an earlier run, to refill the form
     * and to check a returning visitor against.
     */
    public function savedCredentials(): ?DatabaseCredentials
    {
        if (! $this->claimed()) {
            return null;
        }

        $env = $this->installation->env();

        return new DatabaseCredentials(
            host: (string) $env->get('DB_HOST'),
            port: (int) ($env->get('DB_PORT') ?: 3306),
            database: (string) $env->get('DB_DATABASE'),
            username: (string) $env->get('DB_USERNAME'),
            password: (string) $env->get('DB_PASSWORD'),
        );
    }

    public function migrated(): bool
    {
        if (! $this->migrator->repositoryExists()) {
            return false;
        }

        $files = $this->migrator->getMigrationFiles($this->migrationPaths());

        return array_diff(array_keys($files), $this->migrator->getRepository()->getRan()) === [];
    }

    public function migrate(): void
    {
        $status = Artisan::call('migrate', ['--force' => true]);

        if ($status !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'The database tables could not be created.');
        }
    }

    public function starDustReady(): bool
    {
        return collect(StarDustService::STATUS_TABLES)->every(fn (string $table) => Schema::hasTable($table));
    }

    public function bootstrapStarDust(): void
    {
        app(StarDustService::class)->bootstrap();
    }

    /**
     * Create the administrator, write APP_KEY and the tick secret, and
     * close the installer.
     *
     * @return array{cron: string, tickUrl: string, loginUrl: string}
     */
    public function finish(string $siteName, string $appUrl, string $name, string $email, string $password): array
    {
        $appUrl = rtrim($appUrl, '/');

        // Upsert, so a request that died after this line can simply be
        // repeated with the same email.
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => $password,
            'email_verified_at' => now(),
        ])->save();

        Site::query()->find(config()->integer('starsystem.default_site'))?->update([
            'name' => $siteName,
            'domains' => [strtolower(parse_url($appUrl, PHP_URL_HOST) ?: 'localhost')],
        ]);

        $existingSecret = (string) $this->installation->env()->get('STARDUST_TICK_SECRET');
        $secret = $existingSecret !== '' ? $existingSecret : Str::random(48);
        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));

        $this->installation->env()->set([
            'APP_NAME' => $siteName,
            'APP_URL' => $appUrl,
            'STARDUST_TICK_SECRET' => $secret,
            'APP_KEY' => $key,
        ]);

        $this->installation->markInstalled([
            'version' => $this->version(),
            'admin' => $email,
        ]);

        $this->release();
        $this->forgetCachedConfig();

        config(['app.key' => $key, 'app.url' => $appUrl, 'stardust.tick.secret' => $secret]);

        return [
            'cron' => sprintf('* * * * * php %s schedule:run >> /dev/null 2>&1', base_path('artisan')),
            'tickUrl' => $appUrl.'/_system/tick?key='.$secret,
            'loginUrl' => $appUrl.'/login',
        ];
    }

    /**
     * Whoever saved working database details owns the rest of the install.
     * Without this, anyone who found /install between the database step
     * and the last step could create their own administrator.
     */
    public function owns(?string $token): bool
    {
        $hash = @file_get_contents($this->ownerPath());

        return is_string($token) && $token !== '' && is_string($hash) && hash_equals($hash, hash('sha256', $token));
    }

    public function claimed(): bool
    {
        return is_file($this->ownerPath());
    }

    private function claim(): string
    {
        $token = Str::random(64);

        file_put_contents($this->ownerPath(), hash('sha256', $token), LOCK_EX);

        return $token;
    }

    private function release(): void
    {
        @unlink($this->ownerPath());
    }

    private function ownerPath(): string
    {
        return dirname($this->installation->markerPath()).'/install-owner';
    }

    private function version(): string
    {
        return trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown';
    }

    /**
     * @return list<string>
     */
    private function migrationPaths(): array
    {
        return array_values(array_unique([database_path('migrations'), ...$this->migrator->paths()]));
    }

    /**
     * A cached config would hide the new .env from the next request.
     */
    private function forgetCachedConfig(): void
    {
        $cached = app()->getCachedConfigPath();

        if (is_file($cached)) {
            @unlink($cached);
        }
    }
}
