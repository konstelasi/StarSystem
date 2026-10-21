<?php

namespace Tests\Feature\Update;

use App\StarDust\TickPause;
use App\Update\Release;
use App\Update\UpdateException;
use App\Update\Updater;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
 * faithfully; it just can't prove the new PHP code itself would have run.
 * Only an update on a real host shows that.
 */
class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $root;

    private string $work;

    /**
     * The Range header of each request slice() answered.
     *
     * @var list<string|null>
     */
    private array $asked = [];

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

    public function test_the_download_comes_in_slices_across_steps(): void
    {
        $zip = $this->releaseZip('1.1.0');
        Http::fake(fn (Request $request) => $this->slice($request, $zip));

        $updater = $this->updater(slice: 0.0, chunk: 256);
        $updater->start($this->release('1.1.0'));
        $updater->step();

        $state = $updater->state();
        $this->assertSame('download', $state['step']);
        $this->assertSame(256, $state['downloaded']);
        $this->assertSame(strlen($zip), $state['download_size']);
        $this->assertSame(256, filesize("{$this->work}/release.zip.part"));

        $this->drive($updater);

        $this->assertSame('done', $updater->state()['status']);

        $expected = [];

        for ($from = 0; $from < strlen($zip); $from += 256) {
            $expected[] = "bytes={$from}-".($from + 255);
        }

        $this->assertSame($expected, $this->asked);
    }

    public function test_a_step_cut_short_carries_on_from_the_part_file(): void
    {
        $zip = $this->releaseZip('1.1.0');
        Http::fake(fn (Request $request) => $this->slice($request, $zip));

        $updater = $this->updater(chunk: 256);
        $updater->start($this->release('1.1.0'));

        // What a request the host cut short leaves behind.
        $this->write($this->work, 'release.zip.part', substr($zip, 0, 300));

        $this->drive($updater);

        $this->assertSame('done', $updater->state()['status']);
        $this->assertSame('bytes=300-555', $this->asked[0]);
    }

    public function test_a_dropped_connection_is_tried_again(): void
    {
        $zip = $this->releaseZip('1.1.0');
        $calls = 0;

        Http::fake(function (Request $request) use ($zip, &$calls) {
            return ++$calls === 2 ? Http::failedConnection()($request) : $this->slice($request, $zip);
        });

        $updater = $this->updater(slice: 0.0, chunk: 256);
        $updater->start($this->release('1.1.0'));
        $updater->step();
        $updater->step();

        $state = $updater->state();
        $this->assertSame('running', $state['status']);
        $this->assertSame('download', $state['waiting']);
        $this->assertSame(1, $state['download_failures']);
        $this->assertSame(256, $state['downloaded']);

        $this->drive($updater);

        $this->assertSame('done', $updater->state()['status']);
        $this->assertSame('bytes=256-511', $this->asked[1]);
    }

    public function test_a_download_that_keeps_failing_ends_the_update(): void
    {
        Http::fake(['https://example.com/*' => Http::response('busy', 503)]);

        $updater = $this->updater();
        $updater->start($this->release('1.1.0'));

        $this->drive($updater);

        $final = $updater->state();
        $this->assertSame('failed', $final['status']);
        $this->assertSame('download', $final['failed_step']);
        $this->assertStringContainsString('503', $final['error']);
        Http::assertSentCount(3);

        $this->assertFileDoesNotExist("{$this->work}/release.zip.part");
        $this->assertSame("1.0.0\n", file_get_contents("{$this->root}/VERSION"));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertFalse(app(TickPause::class)->active());
    }

    public function test_a_server_that_ignores_range_sends_the_whole_release(): void
    {
        $zip = $this->releaseZip('1.1.0');
        Http::fake(['https://example.com/*' => Http::response($zip)]);

        $updater = $this->updater(chunk: 256);
        $updater->start($this->release('1.1.0'));
        $this->write($this->work, 'release.zip.part', substr($zip, 0, 100));

        $this->drive($updater);

        $this->assertSame('done', $updater->state()['status']);
        Http::assertSentCount(1);
    }

    public function test_a_part_that_already_holds_the_whole_release_is_kept(): void
    {
        $zip = $this->releaseZip('1.1.0');
        $len = strlen($zip);
        Http::fake(fn (Request $request) => $this->slice($request, $zip));

        $updater = $this->updater(chunk: 256);
        $updater->start($this->release('1.1.0'));

        // A step cut after its last slice, before it could save the size.
        $this->write($this->work, 'release.zip.part', $zip);

        $this->drive($updater);

        $this->assertSame('done', $updater->state()['status']);
        $this->assertSame(["bytes={$len}-".($len + 255)], $this->asked);
    }

    public function test_an_answer_for_another_part_is_refused(): void
    {
        $zip = $this->releaseZip('1.1.0');
        $len = strlen($zip);
        Http::fake(['https://example.com/*' => Http::response('xx', 206, ['Content-Range' => "bytes 0-1/{$len}"])]);

        $updater = $this->updater();
        $updater->start($this->release('1.1.0'));
        $this->write($this->work, 'release.zip.part', substr($zip, 0, 300));

        $this->drive($updater);

        $final = $updater->state();
        $this->assertSame('failed', $final['status']);
        $this->assertStringContainsString('different part', $final['error']);
        $this->assertFileDoesNotExist("{$this->work}/release.zip.part");
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

    private function updater(float $slice = 30.0, int $chunk = Updater::DOWNLOAD_CHUNK): Updater
    {
        return new Updater($this->root, $this->work, app(TickPause::class), sliceSeconds: $slice, chunkBytes: $chunk);
    }

    private function release(string $version): Release
    {
        return new Release($version, "https://example.com/starsystem-{$version}.zip", (string) hash('sha256', $this->releaseZip($version)));
    }

    /**
     * Answer a request the way GitHub's download servers do: the asked slice
     * with 206, or 416 past the end.
     */
    private function slice(Request $request, string $body): PromiseInterface
    {
        $range = $request->header('Range')[0] ?? null;
        $this->asked[] = $range;
        $length = strlen($body);

        if ($range === null || preg_match('/^bytes=(\d+)-(\d+)$/', $range, $m) !== 1) {
            return Http::response($body);
        }

        $start = (int) $m[1];

        if ($start >= $length) {
            return Http::response('', 416, ['Content-Range' => "bytes */{$length}"]);
        }

        $end = min((int) $m[2], $length - 1);

        return Http::response(substr($body, $start, $end - $start + 1), 206, ['Content-Range' => "bytes {$start}-{$end}/{$length}"]);
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
