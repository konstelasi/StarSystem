<?php

namespace Tests\Feature\Update;

use App\StarDust\TickPause;
use App\Update\Release;
use App\Update\UpdateException;
use App\Update\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * The step machine against a synthetic install: a root/ and work/ folder
 * standing in for the real ones, never this repository's own files.
 *
 * Because everything here runs in one PHP process, a step such as
 * bootstrap() that in production only runs after the new code has taken
 * over instead runs against this test's own, already-loaded app. That
 * still exercises the state machine, the file swap and the rollback
 * faithfully; it just can't prove the new PHP code itself would have run,
 * which is why the plan for this stream calls for no host trials.
 */
class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $root;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('stardust:bootstrap')->assertSuccessful();

        $this->base = sys_get_temp_dir().'/starsystem-updater-'.bin2hex(random_bytes(6));
        $this->root = "{$this->base}/root";
        $this->work = "{$this->base}/work";

        mkdir($this->root, 0775, true);
        $this->write($this->root, 'VERSION', "1.0.0\n");
        $this->write($this->root, 'app/App.php', '<?php // old');
        $this->write($this->root, 'public/index.php', '<?php // old front controller');
        $this->write($this->root, 'storage/logs/laravel.log', 'kept, never touched');
    }

    protected function tearDown(): void
    {
        // Defensive: a failed assertion mid-test must not leave the real
        // app paused or the real site down for whatever test runs next in
        // this process.
        app(TickPause::class)->resume();

        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }

        $this->removeDir($this->base);

        parent::tearDown();
    }

    public function test_a_full_update_replaces_files_migrates_and_comes_back_up(): void
    {
        Http::fake(['https://example.com/starsystem-1.1.0.zip' => Http::response($this->releaseZip('1.1.0'))]);

        $updater = $this->updater();
        $state = $updater->start($this->release('1.1.0'));

        $this->assertSame('running', $state['status']);
        $this->assertFalse(app()->isDownForMaintenance());

        $this->drive($updater);

        $final = $updater->state();
        $this->assertSame('done', $final['status']);
        $this->assertSame('update', $final['mode']);
        $this->assertNull($final['step']);
        $this->assertNotNull($final['finished_at']);

        $this->assertSame("1.1.0\n", file_get_contents("{$this->root}/VERSION"));
        $this->assertSame('<?php // new', file_get_contents("{$this->root}/app/App.php"));
        $this->assertSame('kept, never touched', file_get_contents("{$this->root}/storage/logs/laravel.log"));

        // The previous version's files are kept in backup/, not thrown away.
        $this->assertSame('<?php // old', file_get_contents("{$this->work}/backup/app/App.php"));

        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFalse(app(TickPause::class)->active());
        $this->assertDirectoryDoesNotExist("{$this->work}/staging");
    }

    public function test_a_download_that_fails_its_checksum_changes_nothing(): void
    {
        Http::fake(['https://example.com/starsystem-1.1.0.zip' => Http::response('not the real zip')]);

        $updater = $this->updater();
        $release = new Release('1.1.0', 'https://example.com/starsystem-1.1.0.zip', str_repeat('a', 64));
        $updater->start($release);

        $this->drive($updater);

        $final = $updater->state();
        $this->assertSame('failed', $final['status']);
        $this->assertSame('download', $final['failed_step']);
        $this->assertStringContainsString('checksum', $final['error']);

        // Nothing was touched: no pause, no maintenance, no file changes.
        $this->assertSame("1.0.0\n", file_get_contents("{$this->root}/VERSION"));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFalse(app(TickPause::class)->active());
    }

    public function test_a_swap_that_cannot_proceed_is_rolled_back(): void
    {
        Http::fake(['https://example.com/starsystem-1.1.0.zip' => Http::response($this->releaseZip('1.1.0'))]);

        $updater = $this->updater();
        $updater->start($this->release('1.1.0'));

        // Run the steps that only prepare, then sabotage the swap: a
        // leftover backup for one of the units, as a crashed earlier
        // update might leave, which FileSwap refuses to overwrite.
        while (in_array($updater->state()['step'], ['download', 'extract', 'check', 'pause'], true)) {
            $updater->step();
        }

        $this->assertSame('swap', $updater->state()['step']);
        $this->write($this->work, 'backup/app/App.php', 'stale');

        $this->drive($updater);

        $final = $updater->state();
        $this->assertSame('rolled_back', $final['status']);
        $this->assertStringContainsString('backup', $final['error']);

        $this->assertSame("1.0.0\n", file_get_contents("{$this->root}/VERSION"));
        $this->assertSame('<?php // old', file_get_contents("{$this->root}/app/App.php"));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFalse(app(TickPause::class)->active());
        $this->assertDirectoryDoesNotExist("{$this->work}/backup");
    }

    public function test_starting_an_older_or_equal_version_is_refused(): void
    {
        $updater = $this->updater();

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('already up to date');

        $updater->start($this->release('1.0.0'));
    }

    public function test_starting_while_one_is_already_running_is_refused(): void
    {
        Http::fake(['https://example.com/starsystem-1.1.0.zip' => Http::response($this->releaseZip('1.1.0'))]);

        $updater = $this->updater();
        $updater->start($this->release('1.1.0'));

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('already under way');

        $updater->start($this->release('1.2.0'));
    }

    private function drive(Updater $updater, int $max = 50): void
    {
        for ($i = 0; $i < $max; $i++) {
            $state = $updater->state();

            if ($state === null || ! in_array($state['status'], ['running'], true)) {
                return;
            }

            $updater->step();
        }

        $this->fail('The update did not finish within '.$max.' steps.');
    }

    private function updater(): Updater
    {
        return new Updater($this->root, $this->work, app(TickPause::class), sliceSeconds: 30.0);
    }

    private function release(string $version): Release
    {
        return new Release($version, "https://example.com/starsystem-{$version}.zip", (string) hash('sha256', $this->releaseZip($version)));
    }

    private function releaseZip(string $version): string
    {
        static $cache = [];

        if (isset($cache[$version])) {
            return $cache[$version];
        }

        $path = sys_get_temp_dir().'/starsystem-updater-src-'.bin2hex(random_bytes(6)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        $zip->addFromString('starsystem/VERSION', $version."\n");
        $zip->addFromString('starsystem/artisan', '#!/usr/bin/env php');
        $zip->addFromString('starsystem/index.php', '<?php // front controller');
        $zip->addFromString('starsystem/bootstrap/app.php', '<?php // bootstrap');
        $zip->addFromString('starsystem/vendor/autoload.php', '<?php // new autoloader');
        $zip->addFromString('starsystem/app/App.php', '<?php // new');
        $zip->addFromString('starsystem/public/index.php', '<?php // new front controller');
        $zip->close();

        $contents = (string) file_get_contents($path);
        unlink($path);

        return $cache[$version] = $contents;
    }

    private function write(string $base, string $relative, string $content): void
    {
        $path = "{$base}/{$relative}";

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = "{$dir}/{$entry}";
            is_dir($path) && ! is_link($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
