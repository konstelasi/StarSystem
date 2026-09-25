<?php

namespace Build;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Builds the zip a shared-host owner uploads: the app at one commit, a
 * vendor/ folder without development packages, and the built front-end.
 *
 * The files come from `git archive`, never from the working tree, so a
 * developer's .env, logs or install marker can't end up in a release.
 */
final class ReleaseBuilder
{
    /**
     * The folder every entry in the zip sits under.
     */
    public const ROOT = 'starsystem';

    /**
     * Tracked top-level entries that a release ships.
     */
    public const SHIP = [
        '.env.example', '.htaccess', 'VERSION', 'app', 'artisan', 'bootstrap', 'composer.json',
        'composer.lock', 'config', 'database', 'index.php', 'modules', 'public', 'resources', 'routes',
        'storage',
    ];

    /**
     * Tracked top-level entries that only matter for development. A new
     * top-level entry has to be added to one list or the other, so nothing
     * is shipped or left out by accident.
     */
    public const SKIP = [
        '.editorconfig', '.gitattributes', '.github', '.gitignore', '.npmrc', 'README.md', 'build',
        'components.json', 'docker', 'docker-compose.yml', 'package-lock.json', 'package.json',
        'phpstan.neon', 'phpunit.xml', 'pint.json', 'pnpm-workspace.yaml', 'tests', 'tsconfig.json',
        'vite.config.ts',
    ];

    /**
     * Front-end sources, replaced in a release by public/build.
     */
    public const SKIP_PATHS = ['resources/js', 'resources/css'];

    /**
     * Folders the app writes to. Only their placeholder files ship, not
     * whatever installing vendor/ generated in them.
     */
    public const RUNTIME_DIRS = ['storage', 'bootstrap/cache'];

    /**
     * @var Closure(string): void
     */
    private readonly Closure $installVendor;

    /**
     * @param  string  $repo  The git checkout to build from.
     * @param  string  $ref  The commit, tag or branch to release.
     * @param  string|null  $assets  The built front-end, public/build by default.
     * @param  (Closure(string): void)|null  $installVendor  Fills vendor/ in the given folder.
     * @param  string  $downloadBase  Where the zip will be downloadable from, for release.json.
     */
    public function __construct(
        private readonly string $repo,
        private readonly string $ref = 'HEAD',
        private readonly ?string $assets = null,
        ?Closure $installVendor = null,
        private readonly string $downloadBase = '',
    ) {
        $this->installVendor = $installVendor ?? $this->composerInstall(...);
    }

    /**
     * @return array{version: string, zip: string, sha256: string, feed: string}
     */
    public function build(string $outDir): array
    {
        $version = $this->version();
        $staging = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-release-'.bin2hex(random_bytes(6));
        $app = $staging.DIRECTORY_SEPARATOR.self::ROOT;

        try {
            $this->export($staging, $app);
            $this->prune($app);
            $this->copyAssets($app);

            ($this->installVendor)($app);
            $this->assertNoDevPackages($app);
            $this->resetRuntimeDirs($app);

            if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
                throw new RuntimeException("Can't create the output folder [{$outDir}].");
            }

            $name = 'starsystem-'.$version.'.zip';
            $zip = rtrim($outDir, '/\\').DIRECTORY_SEPARATOR.$name;
            $this->zip($staging, $zip);

            $hash = hash_file('sha256', $zip);

            if ($hash === false) {
                throw new RuntimeException("Can't read [{$zip}] back to hash it.");
            }

            file_put_contents($zip.'.sha256', $hash.'  '.$name."\n");

            // The updater's feed. Without a download base the zip's URL is
            // relative, and the updater resolves it against the feed's own.
            $feed = dirname($zip).DIRECTORY_SEPARATOR.'release.json';
            file_put_contents($feed, json_encode([
                'version' => $version,
                'zip' => $this->downloadBase.$name,
                'sha256' => $hash,
                'published_at' => gmdate('Y-m-d\TH:i:s\Z', $this->commitTime()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            return ['version' => $version, 'zip' => $zip, 'sha256' => $hash, 'feed' => $feed];
        } finally {
            $this->remove($staging);
        }
    }

    public function version(): string
    {
        $version = trim($this->git(['show', $this->ref.':VERSION']));

        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException("VERSION at [{$this->ref}] isn't a version number: [{$version}].");
        }

        return $version;
    }

    private function export(string $staging, string $app): void
    {
        mkdir($app, 0775, true);

        $archive = $staging.DIRECTORY_SEPARATOR.'source.zip';
        $this->git(['archive', '--format=zip', '-o', $archive, $this->ref]);

        $zip = new ZipArchive;

        if ($zip->open($archive) !== true || ! $zip->extractTo($app)) {
            throw new RuntimeException("Can't unpack the archive of [{$this->ref}].");
        }

        $zip->close();
        unlink($archive);
    }

    private function prune(string $app): void
    {
        $entries = array_diff(scandir($app) ?: [], ['.', '..']);
        $unknown = array_diff($entries, self::SHIP, self::SKIP);

        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                'Add these top-level entries to ReleaseBuilder::SHIP or ::SKIP: %s.',
                implode(', ', $unknown),
            ));
        }

        foreach ([...array_intersect($entries, self::SKIP), ...self::SKIP_PATHS] as $path) {
            $this->remove($app.DIRECTORY_SEPARATOR.$path);
        }
    }

    private function copyAssets(string $app): void
    {
        $assets = $this->assets ?? $this->repo.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';

        if (! is_file($assets.DIRECTORY_SEPARATOR.'manifest.json')) {
            throw new RuntimeException("No built front-end at [{$assets}]. Run `npm run build` first.");
        }

        $target = $app.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';

        foreach ($this->files($assets) as $relative => $file) {
            $to = $target.DIRECTORY_SEPARATOR.$relative;

            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0775, true);
            }

            copy($file->getPathname(), $to);
        }
    }

    private function composerInstall(string $app): void
    {
        $process = new Process(
            ['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist', '--optimize-autoloader'],
            $app,
            timeout: 900,
        );

        $process->mustRun(function (string $type, string $output) {
            fwrite(STDERR, $output);
        });
    }

    private function assertNoDevPackages(string $app): void
    {
        $installed = $app.'/vendor/composer/installed.json';
        $data = is_file($installed) ? json_decode((string) file_get_contents($installed), true) : null;

        if (! is_array($data) || ! array_key_exists('dev', $data)) {
            throw new RuntimeException('vendor/ was not installed by Composer.');
        }

        if ($data['dev'] !== false) {
            throw new RuntimeException('vendor/ includes development packages. Install it with --no-dev.');
        }
    }

    /**
     * Keep only the tracked placeholders in the folders the app writes to,
     * so caches built here, such as bootstrap/cache/packages.php, are made
     * fresh on the host instead.
     */
    private function resetRuntimeDirs(string $app): void
    {
        foreach (self::RUNTIME_DIRS as $dir) {
            foreach ($this->files($app.DIRECTORY_SEPARATOR.$dir) as $file) {
                if ($file->getFilename() !== '.gitignore') {
                    unlink($file->getPathname());
                }
            }
        }
    }

    private function commitTime(): int
    {
        return (int) trim($this->git(['log', '-1', '--format=%ct', $this->ref]));
    }

    private function zip(string $staging, string $target): void
    {
        $time = $this->commitTime();

        if (is_file($target)) {
            unlink($target);
        }

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Can't create [{$target}].");
        }

        $entries = [];

        foreach ($this->files($staging, withDirs: true) as $relative => $file) {
            $entries[str_replace('\\', '/', $relative)] = $file;
        }

        ksort($entries, SORT_STRING);

        foreach ($entries as $name => $file) {
            if ($file->isDir()) {
                $zip->addEmptyDir($name);
                $name .= '/';
                $mode = 0755;
            } else {
                $zip->addFile($file->getPathname(), $name);
                $mode = $name === self::ROOT.'/artisan' ? 0755 : 0644;
            }

            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ($mode | ($file->isDir() ? 0040000 : 0100000)) << 16);
            $zip->setMtimeName($name, $time);
        }

        if (! $zip->close()) {
            throw new RuntimeException("Can't write [{$target}].");
        }
    }

    /**
     * @return iterable<string, SplFileInfo> Keyed by path relative to $dir.
     */
    private function files(string $dir, bool $withDirs = false): iterable
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            $withDirs ? RecursiveIteratorIterator::SELF_FIRST : RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            yield substr($file->getPathname(), strlen($dir) + 1) => $file;
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): string
    {
        return self::withoutAmbientGitEnv(function () use ($args) {
            $process = new Process(['git', '-C', $this->repo, ...$args]);
            $process->mustRun();

            return $process->getOutput();
        });
    }

    /**
     * Variables git sets for its own child processes, such as a
     * `rebase -x` hook's: an ambient GIT_DIR takes precedence over -C, so
     * without this a git command meant for one repository (this one, or a
     * throwaway fixture in a test) can silently run against whichever one
     * spawned this process instead, corrupting it.
     *
     * `putenv()` un-sets these for real, unlike passing null through
     * Process's env array, which PHP would coerce to the string "".
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutAmbientGitEnv(callable $callback): mixed
    {
        $names = ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_CEILING_DIRECTORIES'];
        $previous = [];

        foreach ($names as $name) {
            $previous[$name] = getenv($name);
            putenv($name);
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }
}
