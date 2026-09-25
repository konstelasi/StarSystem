<?php

namespace Tests\Unit;

use Build\ReleaseBuilder;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Builds a release of this repository's HEAD, with a stand-in for
 * `composer install` so the test needs no network.
 */
class ReleaseBuilderTest extends TestCase
{
    /**
     * @var array{version: string, zip: string, sha256: string, feed: string}
     */
    private static array $release;

    /**
     * @var list<string>
     */
    private static array $entries;

    /**
     * @var list<string>
     */
    private static array $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        self::$release = (new ReleaseBuilder(self::repo(), 'HEAD', self::assets(), self::fakeVendor()))
            ->build(self::tempDir());

        $zip = new ZipArchive;
        $zip->open(self::$release['zip']);

        self::$entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            self::$entries[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$cleanup as $dir) {
            (new Process(PHP_OS_FAMILY === 'Windows' ? ['cmd', '/C', 'rmdir', '/S', '/Q', $dir] : ['rm', '-rf', $dir]))->run();
        }
    }

    public function test_the_zip_is_named_after_the_version_with_a_matching_checksum(): void
    {
        $version = trim((string) file_get_contents(self::repo().'/VERSION'));
        $name = 'starsystem-'.$version.'.zip';

        $this->assertSame($version, self::$release['version']);
        $this->assertSame($name, basename(self::$release['zip']));
        $this->assertSame(hash_file('sha256', self::$release['zip']), self::$release['sha256']);
        $this->assertSame(self::$release['sha256'].'  '.$name."\n", file_get_contents(self::$release['zip'].'.sha256'));
    }

    public function test_release_json_describes_the_zip_for_the_updater(): void
    {
        $feed = json_decode((string) file_get_contents(self::$release['feed']), true);

        $this->assertSame('release.json', basename(self::$release['feed']));
        $this->assertSame(self::$release['version'], $feed['version']);
        $this->assertSame(basename(self::$release['zip']), $feed['zip']);
        $this->assertSame(self::$release['sha256'], $feed['sha256']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $feed['published_at']);
    }

    public function test_everything_sits_under_one_folder(): void
    {
        foreach (self::$entries as $entry) {
            $this->assertStringStartsWith('starsystem/', $entry);
        }
    }

    public function test_it_ships_what_a_shared_host_needs(): void
    {
        foreach ([
            'artisan', 'index.php', '.htaccess', '.env.example', 'VERSION', 'composer.json',
            'public/index.php', 'public/.htaccess', 'public/build/manifest.json', 'public/build/assets/app.js',
            'vendor/autoload.php', 'bootstrap/app.php', 'bootstrap/cache/.gitignore',
            'resources/views/app.blade.php', 'routes/install.php', 'app/Install/Installer.php',
            'storage/framework/views/.gitignore', 'storage/logs/.gitignore',
        ] as $path) {
            $this->assertContains('starsystem/'.$path, self::$entries, "Missing {$path}");
        }
    }

    public function test_development_files_stay_out(): void
    {
        foreach ([
            '.env', '.github/', '.gitignore', 'build/', 'docker/', 'node_modules/', 'tests/',
            'package.json', 'phpunit.xml', 'vite.config.ts', 'resources/js/', 'resources/css/',
        ] as $path) {
            $matches = str_ends_with($path, '/')
                ? fn (string $entry) => str_starts_with($entry, 'starsystem/'.$path)
                : fn (string $entry) => $entry === 'starsystem/'.$path;

            $this->assertEmpty(array_filter(self::$entries, $matches), "{$path} should not ship");
        }
    }

    public function test_caches_and_logs_made_while_installing_vendor_stay_out(): void
    {
        $this->assertNotContains('starsystem/bootstrap/cache/packages.php', self::$entries);
        $this->assertNotContains('starsystem/storage/logs/laravel.log', self::$entries);
    }

    public function test_every_top_level_entry_is_classified(): void
    {
        $tracked = ReleaseBuilder::withoutAmbientGitEnv(
            fn () => (new Process(['git', '-C', self::repo(), 'ls-tree', '--name-only', 'HEAD']))->mustRun()->getOutput(),
        );

        foreach (array_filter(explode("\n", $tracked)) as $entry) {
            $this->assertTrue(
                in_array($entry, ReleaseBuilder::SHIP, true) || in_array($entry, ReleaseBuilder::SKIP, true),
                "{$entry} is in neither ReleaseBuilder::SHIP nor ::SKIP",
            );
        }
    }

    public function test_without_ambient_git_env_hides_and_restores_git_dir(): void
    {
        putenv('GIT_DIR=/somewhere/that/was/never/meant/to/be/used');

        try {
            $seenInside = ReleaseBuilder::withoutAmbientGitEnv(fn () => getenv('GIT_DIR'));

            $this->assertFalse($seenInside);
            $this->assertSame('/somewhere/that/was/never/meant/to/be/used', getenv('GIT_DIR'));
        } finally {
            putenv('GIT_DIR');
        }
    }

    public function test_an_unclassified_top_level_entry_stops_the_build(): void
    {
        $repo = self::tempDir();
        file_put_contents($repo.'/VERSION', "1.2.3\n");
        file_put_contents($repo.'/mystery.txt', "?\n");

        // Isolated for real: an ambient GIT_DIR (set for us here by, say,
        // a `rebase -x` hook running this very test) would otherwise beat
        // -C and point every one of these commands at the real repository
        // instead of this throwaway one.
        ReleaseBuilder::withoutAmbientGitEnv(function () use ($repo) {
            foreach ([['init', '-q'], ['add', '.'], ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'Fixture']] as $args) {
                (new Process(['git', '-C', $repo, ...$args]))->mustRun();
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mystery.txt');

        (new ReleaseBuilder($repo, 'HEAD', self::assets(), self::fakeVendor()))->build(self::tempDir());
    }

    public function test_a_vendor_folder_with_development_packages_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--no-dev');

        (new ReleaseBuilder(self::repo(), 'HEAD', self::assets(), self::fakeVendor(dev: true)))->build(self::tempDir());
    }

    public function test_a_missing_front_end_build_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');

        (new ReleaseBuilder(self::repo(), 'HEAD', self::tempDir(), self::fakeVendor()))->build(self::tempDir());
    }

    private static function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-release-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);

        return self::$cleanup[] = $dir;
    }

    private static function assets(): string
    {
        $dir = self::tempDir();
        mkdir($dir.'/assets');
        file_put_contents($dir.'/manifest.json', '{}');
        file_put_contents($dir.'/assets/app.js', '');

        return $dir;
    }

    /**
     * Stands in for `composer install`, including the caches and logs a
     * real one leaves behind through package:discover.
     *
     * @return Closure(string): void
     */
    private static function fakeVendor(bool $dev = false): Closure
    {
        return function (string $app) use ($dev) {
            mkdir($app.'/vendor/composer', 0775, true);
            file_put_contents($app.'/vendor/autoload.php', "<?php\n");
            file_put_contents($app.'/vendor/composer/installed.json', json_encode(['packages' => [], 'dev' => $dev]));
            file_put_contents($app.'/bootstrap/cache/packages.php', "<?php return [];\n");
            file_put_contents($app.'/storage/logs/laravel.log', "log\n");
        };
    }
}
