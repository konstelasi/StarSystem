<?php

namespace Tests\Feature\Files;

use App\Files\File;
use App\Files\FileStore;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CrossSite;
use Tests\Concerns\FakeUploads;
use Tests\TestCase;

class FileApiTest extends TestCase
{
    use CrossSite, FakeUploads, RefreshDatabase;

    private Filesystem $disk;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('files');
        $this->user = User::factory()->create();
        $this->asSite($this->defaultSite());
    }

    private function stored(UploadedFile $upload, string $folder = '', ?Site $site = null): File
    {
        if ($site !== null) {
            $this->asSite($site);
        }

        return app(FileStore::class)->store($upload, $folder);
    }

    public function test_guests_cannot_use_the_api()
    {
        $this->getJson(route('admin.api.files.index'))->assertUnauthorized();
        $this->postJson(route('admin.api.files.store'), ['files' => [$this->png()]])->assertUnauthorized();

        $this->assertSame(0, File::query()->count());
    }

    public function test_several_files_upload_in_one_request()
    {
        $response = $this->actingAs($this->user)
            ->post(route('admin.api.files.store'), [
                'files' => [$this->png('One.png'), $this->pdf('Two.pdf')],
                'folder' => 'Press/2026',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'One.png')
            ->assertJsonPath('data.0.kind', 'image')
            ->assertJsonPath('data.0.folder', 'Press/2026')
            ->assertJsonPath('data.0.uploadedBy', $this->user->name)
            ->assertJsonPath('data.1.mime', 'application/pdf');

        $file = File::query()->where('uuid', $response->json('data.0.uuid'))->firstOrFail();

        $this->assertSame($this->user->id, $file->uploaded_by);
        $this->disk->assertExists($file->path);
        $this->assertStringNotContainsString('One', $file->path);
        $this->assertStringEndsWith("/files/{$file->uuid}/One.png", (string) $response->json('data.0.url'));
        $this->assertArrayNotHasKey('path', $response->json('data.0'));
        $this->assertArrayNotHasKey('id', $response->json('data.0'));
    }

    public function test_a_rejected_file_fails_the_whole_batch_with_a_plain_message()
    {
        $this->actingAs($this->user)
            ->post(route('admin.api.files.store'), [
                'files' => [$this->png(), $this->upload('avatar.jpg', "<?php echo 'hello'; ?>"), $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors('files.0')
            ->assertJsonValidationErrors([
                'files.1' => 'could run code',
                'files.2' => 'could run code',
            ]);

        $this->assertSame(0, File::query()->count());
        $this->assertSame([], $this->disk->allFiles());
    }

    public function test_files_over_the_size_limit_are_rejected()
    {
        config(['files.max_size' => 10]);

        $this->actingAs($this->user)
            ->post(route('admin.api.files.store'), ['files' => [$this->png()]], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => 'This file is larger than the 10 bytes StarSystem allows.']);
    }

    public function test_uploads_need_files_and_a_valid_folder()
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.api.files.store'), ['folder' => '../etc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files' => 'Choose at least one file', 'folder' => 'can\'t be used as a folder name']);
    }

    public function test_the_list_filters_by_folder_and_reports_folders_and_limits()
    {
        $this->stored($this->png('root.png'));
        $this->stored($this->png('nested.png'), 'Photos/2026');
        $this->stored($this->pdf('brochure.pdf'), 'Docs');

        $this->actingAs($this->user)
            ->getJson(route('admin.api.files.index', ['folder' => 'Photos/2026']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'nested.png')
            ->assertJsonPath('folders', ['Docs', 'Photos', 'Photos/2026'])
            ->assertJsonPath('limits.maxFileBytes', fn ($bytes) => is_int($bytes) && $bytes > 0);

        $this->actingAs($this->user)
            ->getJson(route('admin.api.files.index').'?folder=')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'root.png');

        $this->actingAs($this->user)
            ->getJson(route('admin.api.files.index'))
            ->assertJsonCount(3, 'data');
    }

    public function test_the_list_searches_and_filters_by_kind_and_uuid()
    {
        $photo = $this->stored($this->png('Beach photo.png'), 'Photos');
        $this->stored($this->pdf('Beach report.pdf'));
        $this->stored($this->mp4('Beach clip.mp4'));
        $this->stored($this->png('100%_done.png'));

        $index = fn (array $query) => $this->actingAs($this->user)->getJson(route('admin.api.files.index', $query));

        $index(['q' => 'beach'])->assertJsonCount(3, 'data');
        $index(['q' => '%'])->assertJsonCount(1, 'data');
        $index(['kinds' => ['image']])->assertJsonCount(2, 'data');
        $index(['kinds' => ['document', 'video']])->assertJsonCount(2, 'data');
        $index(['uuids' => [$photo->uuid], 'folder' => 'elsewhere'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $photo->uuid);
        $index(['kinds' => ['executable']])->assertStatus(422);
    }

    public function test_the_list_pages_with_a_cursor()
    {
        foreach (range(1, 3) as $n) {
            $this->stored($this->png("{$n}.png"));
        }

        $first = $this->actingAs($this->user)
            ->getJson(route('admin.api.files.index', ['per_page' => 2]))
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '3.png');

        $cursor = $first->json('nextCursor');
        $this->assertIsString($cursor);

        $this->actingAs($this->user)
            ->getJson(route('admin.api.files.index', ['per_page' => 2, 'cursor' => $cursor]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '1.png')
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('folders', null);
    }

    public function test_a_file_can_be_renamed_described_and_moved()
    {
        $file = $this->stored($this->png('old.png'));

        $this->actingAs($this->user)
            ->patchJson(route('admin.api.files.update', $file->uuid), [
                'name' => 'Team photo',
                'alt' => 'The team on the beach',
                'folder' => '/Team/',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Team photo.png')
            ->assertJsonPath('data.alt', 'The team on the beach')
            ->assertJsonPath('data.folder', 'Team');

        $this->actingAs($this->user)
            ->patchJson(route('admin.api.files.update', $file->uuid), ['alt' => null])
            ->assertOk()
            ->assertJsonPath('data.alt', null)
            ->assertJsonPath('data.name', 'Team photo.png');
    }

    public function test_bad_changes_are_rejected()
    {
        $file = $this->stored($this->png());

        $this->actingAs($this->user)
            ->patchJson(route('admin.api.files.update', $file->uuid), ['name' => '', 'folder' => 'a/../b'])
            ->assertJsonValidationErrors(['name' => 'can\'t be empty', 'folder']);

        $this->actingAs($this->user)
            ->patchJson(route('admin.api.files.update', $file->uuid), ['name' => 'x/y'])
            ->assertJsonValidationErrors(['name' => 'can\'t contain slashes']);
    }

    public function test_a_deleted_file_is_gone_from_the_list_and_the_web()
    {
        $file = $this->stored($this->png());

        $this->actingAs($this->user)
            ->deleteJson(route('admin.api.files.destroy', $file->uuid))
            ->assertNoContent();

        $this->actingAs($this->user)->getJson(route('admin.api.files.index'))->assertJsonCount(0, 'data');
        $this->get($file->url())->assertNotFound();
        $this->actingAs($this->user)->deleteJson(route('admin.api.files.destroy', $file->uuid))->assertNotFound();
    }

    public function test_another_site_cannot_list_change_or_delete_a_sites_files()
    {
        config(['starsystem.multisite' => true]);
        $siteA = $this->makeSite('a.example.com', 'Site A');
        $this->makeSite('b.example.com', 'Site B');

        $file = $this->stored($this->png('secret.png'), 'Private', $siteA);

        $this->actingAs($this->user)
            ->getJson('http://a.example.com/admin/api/files')
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->user)
            ->getJson('http://b.example.com/admin/api/files')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('folders', []);

        $this->actingAs($this->user)
            ->getJson("http://b.example.com/admin/api/files?uuids[]={$file->uuid}")
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->user)
            ->patchJson("http://b.example.com/admin/api/files/{$file->uuid}", ['name' => 'pwned'])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->deleteJson("http://b.example.com/admin/api/files/{$file->uuid}")
            ->assertNotFound();

        $this->get("http://b.example.com/files/{$file->uuid}/secret.png")->assertNotFound();

        $this->asSite($siteA);
        $this->assertSame('secret.png', $file->fresh()?->original_name);
    }

    public function test_uploads_land_in_the_requesting_sites_folder()
    {
        config(['starsystem.multisite' => true]);
        $siteB = $this->makeSite('b.example.com', 'Site B');

        $uuid = $this->actingAs($this->user)
            ->post('http://b.example.com/admin/api/files', ['files' => [$this->png()]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.0.uuid');

        $this->asSite($siteB);
        $file = File::query()->where('uuid', $uuid)->firstOrFail();

        $this->assertStringStartsWith("{$siteB->id}/", $file->path);
        $this->asSite($this->defaultSite());
        $this->assertSame(0, File::query()->count());
    }
}
