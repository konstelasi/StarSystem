<?php

namespace Tests\Feature\Schema;

use App\Files\File;
use App\Files\FileStore;
use App\Models\Site;
use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\FieldTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CrossSite;
use Tests\Concerns\FakeUploads;
use Tests\TestCase;

/**
 * The file field type's rules() must check a picked uuid against the
 * current site's own files, not just any row in the files table: a plain
 * `exists:files,uuid` rule would ignore File's site scope and let one
 * site's uuid validate for every other site.
 */
class FileFieldValidationTest extends TestCase
{
    use CrossSite, FakeUploads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        $this->asSite($this->defaultSite());
    }

    public function test_an_existing_files_uuid_passes()
    {
        $file = $this->stored();

        $this->assertTrue($this->passesSingle($file->uuid));
    }

    public function test_a_uuid_that_does_not_exist_fails()
    {
        $this->assertFalse($this->passesSingle((string) Str::uuid()));
    }

    public function test_another_sites_file_fails()
    {
        $other = $this->makeSite('other.test');
        $file = $this->stored($other);
        $this->asSite($this->defaultSite());

        $this->assertFalse($this->passesSingle($file->uuid));
    }

    public function test_multiple_requires_every_uuid_to_exist_for_the_site()
    {
        $known = $this->stored();
        $unknown = (string) Str::uuid();

        $rules = $this->fieldType()->rules(['multiple' => true], false);

        $this->assertTrue(
            validator(['value' => [$known->uuid]], ['value' => $rules[''], 'value.*' => $rules['.*']])->passes()
        );
        $this->assertFalse(
            validator(['value' => [$known->uuid, $unknown]], ['value' => $rules[''], 'value.*' => $rules['.*']])->passes()
        );
    }

    private function passesSingle(string $uuid): bool
    {
        $rules = $this->fieldType()->rules([], false);

        return validator(['value' => $uuid], ['value' => $rules['']])->passes();
    }

    private function fieldType(): FieldType
    {
        return app(FieldTypeRegistry::class)->get('file');
    }

    private function stored(?Site $site = null): File
    {
        if ($site !== null) {
            $this->asSite($site);
        }

        return app(FileStore::class)->store($this->upload('a.pdf', $this->pdfBytes()));
    }
}
