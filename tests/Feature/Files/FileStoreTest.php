<?php

namespace Tests\Feature\Files;

use App\Files\File;
use App\Files\FileRejected;
use App\Files\FileStore;
use App\Files\TrashPurger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CrossSite;
use Tests\Concerns\FakeUploads;
use Tests\TestCase;

class FileStoreTest extends TestCase
{
    use CrossSite, FakeUploads, RefreshDatabase;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('files');
        $this->asSite($this->defaultSite());
    }

    private function store(): FileStore
    {
        return app(FileStore::class);
    }

    public function test_the_files_disk_is_outside_the_public_folder()
    {
        $root = str_replace('\\', '/', config()->string('filesystems.disks.files.root'));

        $this->assertSame(str_replace('\\', '/', storage_path('app/sites')), $root);
        $this->assertStringStartsNotWith(str_replace('\\', '/', public_path()), $root);
    }

    public function test_an_upload_is_stored_under_its_site_with_a_random_name()
    {
        $file = $this->store()->store($this->png('Holiday Photo.png'), 'photos/2026', uploadedBy: null);

        $this->assertMatchesRegularExpression('#^1/\d{4}/\d{2}/[0-9a-f]{40}\.png$#', $file->path);
        $this->assertStringNotContainsString('Holiday', $file->path);
        $this->disk->assertExists($file->path);

        $this->assertSame('Holiday Photo.png', $file->original_name);
        $this->assertSame('photos/2026', $file->folder);
        $this->assertSame(1, $file->tenant_id);
        $this->assertSame(hash('sha256', $this->pngBytes()), $file->sha256);
        $this->assertSame(strlen($this->pngBytes()), $file->size);
        $this->assertSame([1, 1], [$file->width, $file->height]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $file->uuid);
    }

    public function test_the_type_is_detected_from_the_contents_not_the_client()
    {
        $upload = $this->upload('photo.png', $this->pngBytes(), clientMime: 'application/x-php');

        $this->assertSame('image/png', $this->store()->store($upload)->mime);
        $this->assertSame('application/pdf', $this->store()->store($this->pdf())->mime);
        $this->assertSame('video/mp4', $this->store()->store($this->mp4())->mime);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function deniedUploads(): array
    {
        return [
            // Harmless payloads: a local virus scanner removes real web shells
            // from the temp folder before the test can read them.
            'php renamed to jpg' => ['avatar.jpg', "<?php echo 'hello'; ?>", 'could run code'],
            'php' => ['shell.php', '<?php echo 1; ?>', 'could run code'],
            'image named php' => ['shell.php', "\x89PNG\r\n\x1a\n", 'could run code'],
            'phtml' => ['shell.phtml', '<?php echo 1; ?>', 'could run code'],
            'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'could run code'],
            'svg renamed to png' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'could run code'],
            'html renamed to pdf' => ['report.pdf', '<!DOCTYPE html><html><script>alert(1)</script></html>', 'could run code'],
            'javascript' => ['app.js', 'alert(1);', 'could run code'],
            'htaccess' => ['.htaccess', 'AddHandler application/x-httpd-php .jpg', 'could run code'],
            'unlisted type' => ['notes.txt', 'hello', '.txt files aren\'t allowed'],
            'contents do not match' => ['photo.jpg', "%PDF-1.4\n%%EOF\n", 'don\'t match its .jpg extension'],
            'empty' => ['empty.pdf', '', 'empty'],
        ];
    }

    #[DataProvider('deniedUploads')]
    public function test_denied_types_are_rejected(string $name, string $contents, string $reason)
    {
        try {
            $this->store()->store($this->upload($name, $contents));
            $this->fail("{$name} was stored.");
        } catch (FileRejected $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame(0, File::query()->count());
        $this->assertSame([], $this->disk->allFiles());
    }

    public function test_the_deny_list_wins_over_the_allowed_types()
    {
        config(['files.types.svg' => ['image/svg+xml']]);

        $this->expectException(FileRejected::class);

        $this->store()->store($this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'));
    }

    public function test_uploads_over_the_configured_limit_are_rejected()
    {
        config(['files.max_size' => 10]);

        $this->expectExceptionMessage('This file is larger than the 10 bytes StarSystem allows.');

        $this->store()->store($this->png());
    }

    public function test_uploads_php_refused_for_size_are_reported_in_plain_words()
    {
        $upload = new UploadedFile((string) tempnam(sys_get_temp_dir(), 'ss'), 'big.png', null, UPLOAD_ERR_INI_SIZE, true);

        $this->expectExceptionMessageMatches('/^This file is larger than (your host allows \(.+\)|the .+ StarSystem allows)\.$/');

        $this->store()->store($upload);
    }

    public function test_a_failed_write_is_reported_in_plain_words_and_leaves_no_row()
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(UnableToWriteFile::atLocation('1/x.png', 'No space left on device'));
        Storage::set('files', $disk);

        try {
            $this->store()->store($this->png());
            $this->fail('The upload was stored.');
        } catch (FileRejected $e) {
            $this->assertStringContainsString('couldn\'t save this file', $e->getMessage());
        }

        $this->assertSame(0, File::query()->count());
    }

    public function test_renaming_changes_only_the_display_name_and_keeps_the_extension()
    {
        $file = $this->store()->store($this->png());
        $path = $file->path;

        $this->assertSame('Team photo.png', $this->store()->rename($file, 'Team photo')->original_name);
        $this->assertSame('evil.php.png', $this->store()->rename($file, '../evil.php')->original_name);
        $this->assertSame('Final.png', $this->store()->rename($file, 'Final.PNG')->original_name);
        $this->assertSame($path, $file->fresh()?->path);
    }

    public function test_moving_normalises_the_virtual_folder()
    {
        $file = $this->store()->store($this->png());

        $this->assertSame('Photos/2026', $this->store()->move($file, ' /Photos//2026/ ')->folder);
        $this->assertSame('', $this->store()->move($file, '/')->folder);
    }

    public function test_folders_cannot_climb_out()
    {
        $file = $this->store()->store($this->png());

        $this->expectException(FileRejected::class);

        $this->store()->move($file, 'photos/../../etc');
    }

    public function test_alt_text_is_saved_and_blank_clears_it()
    {
        $file = $this->store()->store($this->png());

        $this->assertSame('A red dot', $this->store()->describe($file, ' A red dot ')->alt);
        $this->assertNull($this->store()->describe($file, '  ')->alt);
    }

    public function test_deleting_hides_the_file_and_purging_removes_its_bytes()
    {
        $file = $this->store()->store($this->png());

        $this->store()->delete($file);

        $this->assertSame(0, File::query()->count());
        $this->disk->assertExists($file->path);

        $this->store()->purge($file);

        $this->assertSame(0, File::withTrashed()->count());
        $this->disk->assertMissing($file->path);
    }

    public function test_the_trash_is_purged_after_the_retention_period_for_every_site()
    {
        $old = $this->store()->store($this->png());
        $recent = $this->store()->store($this->png());

        $this->asSite($this->makeSite('other.example.com'));
        $otherOld = $this->store()->store($this->png());

        foreach ([$old, $otherOld] as $file) {
            $file->deleted_at = now()->subDays(31);
            $file->save();
        }
        $this->store()->delete($recent);

        $this->assertSame(2, app(TrashPurger::class)->purge());

        $this->disk->assertMissing([$old->path, $otherOld->path]);
        $this->disk->assertExists($recent->path);
        $this->assertSame(1, File::withoutGlobalScope('site')->withTrashed()->count());
    }

    public function test_the_purge_command_runs()
    {
        $this->artisan('files:purge-trash')->assertSuccessful();
    }

    public function test_files_are_invisible_across_sites()
    {
        $other = $this->makeSite('other.example.com');

        $this->assertInvisibleAcrossSites(
            owner: $other,
            other: $this->defaultSite(),
            write: fn () => $this->store()->store($this->png()),
            read: fn () => File::query()->count(),
        );
    }

    public function test_each_site_stores_in_its_own_folder()
    {
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);

        $file = $this->store()->store($this->png());

        $this->assertStringStartsWith($other->id.'/', $file->path);
        $this->assertSame($other->id, $file->tenant_id);
    }
}
