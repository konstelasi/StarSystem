<?php

namespace Tests\Unit\Update;

use App\Update\FileSwap;
use App\Update\UpdateException;
use PHPUnit\Framework\TestCase;

/**
 * FileSwap against plain temp folders standing in for the install, never
 * against this repository's own files.
 */
class FileSwapTest extends TestCase
{
    private string $root;

    private string $staging;

    private string $backup;

    private string $discard;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/starsystem-swap-'.bin2hex(random_bytes(6));
        mkdir($base, 0775, true);

        $this->root = "{$base}/root";
        $this->staging = "{$base}/staging";
        $this->backup = "{$base}/backup";
        $this->discard = "{$base}/discard";

        mkdir($this->root, 0775, true);
        mkdir($this->staging, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir(dirname($this->root));

        parent::tearDown();
    }

    public function test_units_lists_top_level_entries_with_public_expanded_and_storage_excluded(): void
    {
        $this->write($this->staging, 'app/App.php', 'x');
        $this->write($this->staging, 'vendor/autoload.php', 'x');
        $this->write($this->staging, 'public/index.php', 'x');
        $this->write($this->staging, 'public/build/app.js', 'x');
        $this->write($this->staging, 'storage/logs/laravel.log', 'x');

        $units = FileSwap::units($this->staging);

        $this->assertSame(['app', 'public/build', 'public/index.php', 'vendor'], $units);
    }

    public function test_apply_replaces_live_files_and_keeps_a_backup(): void
    {
        $this->write($this->root, 'app/App.php', 'old');
        $this->write($this->staging, 'app/App.php', 'new');

        (new FileSwap($this->root, $this->staging, $this->backup, $this->discard))->apply(['app']);

        $this->assertSame('new', file_get_contents("{$this->root}/app/App.php"));
        $this->assertSame('old', file_get_contents("{$this->backup}/app/App.php"));
        $this->assertDirectoryDoesNotExist("{$this->staging}/app");
    }

    public function test_apply_adds_a_unit_that_did_not_exist_before(): void
    {
        $this->write($this->staging, 'public/build/app.js', 'new');

        (new FileSwap($this->root, $this->staging, $this->backup, $this->discard))->apply(['public/build']);

        $this->assertSame('new', file_get_contents("{$this->root}/public/build/app.js"));
        $this->assertDirectoryDoesNotExist("{$this->backup}/public/build");
    }

    public function test_additions_are_the_units_with_no_live_counterpart_yet(): void
    {
        $this->write($this->root, 'app/App.php', 'old');

        $swap = new FileSwap($this->root, $this->staging, $this->backup, $this->discard);

        $this->assertSame(['public/build'], $swap->additions(['app', 'public/build']));
    }

    public function test_apply_skips_a_unit_the_release_does_not_ship(): void
    {
        $this->write($this->root, 'public/uploads/photo.jpg', 'kept');

        (new FileSwap($this->root, $this->staging, $this->backup, $this->discard))->apply(['public/uploads']);

        $this->assertSame('kept', file_get_contents("{$this->root}/public/uploads/photo.jpg"));
    }

    public function test_apply_refuses_to_overwrite_an_existing_backup(): void
    {
        $this->write($this->root, 'app/App.php', 'old');
        $this->write($this->staging, 'app/App.php', 'new');
        $this->write($this->backup, 'app/App.php', 'stale backup from a previous, unfinished update');

        $this->expectException(UpdateException::class);

        (new FileSwap($this->root, $this->staging, $this->backup, $this->discard))->apply(['app']);
    }

    public function test_restore_puts_a_replaced_unit_back(): void
    {
        $this->write($this->root, 'app/App.php', 'old');
        $this->write($this->staging, 'app/App.php', 'new');

        $swap = new FileSwap($this->root, $this->staging, $this->backup, $this->discard);
        $swap->apply(['app']);
        $swap->restore(['app']);

        $this->assertSame('old', file_get_contents("{$this->root}/app/App.php"));
        $this->assertFileDoesNotExist("{$this->backup}/app/App.php");
    }

    public function test_restore_removes_a_unit_that_did_not_exist_before(): void
    {
        $this->write($this->staging, 'public/build/app.js', 'new');

        $swap = new FileSwap($this->root, $this->staging, $this->backup, $this->discard);
        $added = $swap->additions(['public/build']);
        $swap->apply(['public/build']);
        $swap->restore(['public/build'], $added);

        $this->assertFileDoesNotExist("{$this->root}/public/build/app.js");
    }

    public function test_restore_after_apply_died_between_the_backup_and_the_move_in(): void
    {
        // Simulates a crash right after the live file was moved to backup/
        // but before staging/ was moved into place: staging/ still holds
        // the new file, backup/ holds the old one, and root/ has neither.
        $this->write($this->staging, 'app/App.php', 'new');
        $this->write($this->backup, 'app/App.php', 'old');

        (new FileSwap($this->root, $this->staging, $this->backup, $this->discard))->restore(['app']);

        $this->assertSame('old', file_get_contents("{$this->root}/app/App.php"));
    }

    public function test_restore_is_safe_to_call_twice(): void
    {
        $this->write($this->root, 'app/App.php', 'old');
        $this->write($this->staging, 'app/App.php', 'new');

        $swap = new FileSwap($this->root, $this->staging, $this->backup, $this->discard);
        $swap->apply(['app']);
        $swap->restore(['app']);
        $swap->restore(['app']);

        $this->assertSame('old', file_get_contents("{$this->root}/app/App.php"));
    }

    public function test_restoring_an_added_unit_is_safe_to_call_twice(): void
    {
        $this->write($this->staging, 'public/build/app.js', 'new');

        $swap = new FileSwap($this->root, $this->staging, $this->backup, $this->discard);
        $added = $swap->additions(['public/build']);
        $swap->apply(['public/build']);
        $swap->restore(['public/build'], $added);
        $swap->restore(['public/build'], $added);

        $this->assertFileDoesNotExist("{$this->root}/public/build/app.js");
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
