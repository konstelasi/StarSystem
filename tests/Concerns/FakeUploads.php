<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Real file contents for upload tests, so fileinfo detects them the way it
 * would a real upload. Built from bytes rather than GD, which a CI image
 * may not have.
 */
trait FakeUploads
{
    protected function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    protected function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    protected function mp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64);
    }

    protected function upload(string $name, string $contents, ?string $clientMime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ss-upload-');
        file_put_contents($path, $contents);

        // The client type is deliberately wrong unless given: nothing may
        // trust it.
        return new UploadedFile($path, $name, $clientMime ?? 'application/octet-stream', null, true);
    }

    protected function png(string $name = 'photo.png'): UploadedFile
    {
        return $this->upload($name, $this->pngBytes());
    }

    protected function pdf(string $name = 'report.pdf'): UploadedFile
    {
        return $this->upload($name, $this->pdfBytes());
    }

    protected function mp4(string $name = 'clip.mp4'): UploadedFile
    {
        return $this->upload($name, $this->mp4Bytes());
    }
}
