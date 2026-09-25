<?php

namespace Tests\Feature\Files;

use App\Files\File;
use App\Files\FileServer;
use App\Files\FileStore;
use App\Models\Site;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CrossSite;
use Tests\Concerns\FakeUploads;
use Tests\TestCase;

class ServeFileTest extends TestCase
{
    use CrossSite, FakeUploads, RefreshDatabase;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('files');
        $this->asSite($this->defaultSite());
    }

    private function stored(UploadedFile $upload, ?Site $site = null): File
    {
        if ($site !== null) {
            $this->asSite($site);
        }

        return app(FileStore::class)->store($upload);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function body(TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    public function test_an_image_is_served_inline_with_protective_headers()
    {
        $file = $this->stored($this->png('Holiday.png'));

        $response = $this->get($file->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', FileServer::CSP)
            ->assertHeader('Content-Disposition', 'inline; filename=Holiday.png')
            ->assertHeader('ETag', '"'.$file->sha256.'"')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', (string) strlen($this->pngBytes()))
            ->assertHeaderMissing('Set-Cookie');

        $this->assertStringContainsString('sandbox', FileServer::CSP);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
        $this->assertSame($this->pngBytes(), $this->body($response));
    }

    public function test_the_name_segment_is_optional_and_ignored()
    {
        $file = $this->stored($this->png());

        $this->get("/files/{$file->uuid}")->assertOk();
        $this->get("/files/{$file->uuid}/anything-else.exe")->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_pdfs_are_inline_without_the_sandbox_their_viewer_cannot_run_in()
    {
        $file = $this->stored($this->pdf());

        $this->get($file->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Security-Policy', FileServer::PDF_CSP)
            ->assertHeader('Content-Disposition', 'inline; filename=report.pdf');
    }

    public function test_other_files_download_as_attachments()
    {
        $file = $this->stored($this->upload('Price list.zip', "PK\x05\x06".str_repeat("\x00", 18)));

        $this->get($file->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('Content-Disposition', 'attachment; filename="Price list.zip"')
            ->assertHeader('Content-Security-Policy', FileServer::CSP);
    }

    public function test_non_ascii_names_get_an_ascii_fallback()
    {
        $file = $this->stored($this->png('Café 東京.png'));

        $disposition = (string) $this->get($file->url())->headers->get('Content-Disposition');

        $this->assertStringContainsString('filename="Cafe .png"', $disposition);
        $this->assertStringContainsString("filename*=utf-8''Caf%C3%A9%20%E6%9D%B1%E4%BA%AC.png", $disposition);
    }

    public function test_a_matching_etag_gets_304()
    {
        $file = $this->stored($this->png());

        $response = $this->get($file->url(), ['If-None-Match' => '"'.$file->sha256.'"'])
            ->assertStatus(304);

        $this->assertSame('', $this->body($response));
        $this->get($file->url(), ['If-None-Match' => '"stale"'])->assertOk();
    }

    public function test_an_unchanged_last_modified_gets_304()
    {
        $file = $this->stored($this->png());

        $this->get($file->url(), ['If-Modified-Since' => $file->created_at->addMinute()->toRfc7231String()])
            ->assertStatus(304);
    }

    public function test_video_can_be_fetched_in_ranges()
    {
        $bytes = $this->mp4Bytes();
        $file = $this->stored($this->mp4());
        $total = strlen($bytes);

        $response = $this->get($file->url(), ['Range' => 'bytes=4-11'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', "bytes 4-11/{$total}")
            ->assertHeader('Content-Length', '8')
            ->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame(substr($bytes, 4, 8), $this->body($response));

        $tail = $this->get($file->url(), ['Range' => 'bytes=-6'])->assertStatus(206);
        $this->assertSame(substr($bytes, -6), $this->body($tail));

        $this->get($file->url(), ['Range' => "bytes={$total}-".($total + 10)])->assertStatus(416);
    }

    public function test_a_stale_if_range_gets_the_whole_file()
    {
        $file = $this->stored($this->mp4());

        $this->get($file->url(), ['Range' => 'bytes=0-3', 'If-Range' => '"old"'])
            ->assertOk()
            ->assertHeader('Content-Length', (string) strlen($this->mp4Bytes()));
    }

    public function test_trashed_files_are_not_served()
    {
        $file = $this->stored($this->png());
        app(FileStore::class)->delete($file);

        $this->disk->assertExists($file->path);
        $this->get($file->url())->assertNotFound();
    }

    public function test_unknown_and_malformed_ids_are_not_found()
    {
        $this->get('/files/0197a0d1-0000-7000-8000-000000000000/x.png')->assertNotFound();
        $this->get('/files/not-a-uuid')->assertNotFound();
        $this->get('/files/1')->assertNotFound();
    }

    public function test_files_whose_bytes_are_gone_are_not_found()
    {
        $file = $this->stored($this->png());
        $this->disk->delete($file->path);

        $this->get($file->url())->assertNotFound();
    }

    public function test_a_site_cannot_serve_another_sites_file()
    {
        config(['starsystem.multisite' => true]);
        $siteA = $this->makeSite('a.example.com', 'Site A');
        $this->makeSite('b.example.com', 'Site B');

        $file = $this->stored($this->png(), $siteA);

        $this->get("http://a.example.com/files/{$file->uuid}/photo.png")->assertOk();
        $this->get("http://b.example.com/files/{$file->uuid}/photo.png")->assertNotFound();
    }

    public function test_single_site_mode_serves_only_the_default_sites_files()
    {
        $other = $this->makeSite('other.example.com');
        $file = $this->stored($this->png(), $other);

        $this->get("http://other.example.com/files/{$file->uuid}")->assertNotFound();
    }
}
