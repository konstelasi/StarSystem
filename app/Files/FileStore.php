<?php

namespace App\Files;

use App\Sites\CurrentSite;
use finfo;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Owns the bytes of every site's uploads: it stores, renames, moves and
 * deletes files, and nothing else writes to the files disk.
 *
 * Stored names are random and carry the extension the policy accepted, so
 * nothing the uploader typed ever becomes part of a path on disk. The
 * uploader's name is kept only as the display name.
 *
 * Identical uploads are not de-duplicated. Sharing one blob between rows
 * would need reference counting on every delete and purge, and each row
 * has its own name, folder and alt text anyway; a CMS rarely stores the
 * same file twice. The sha256 is kept so the admin can warn about
 * duplicates, and to serve as the ETag.
 */
class FileStore
{
    public function __construct(
        private readonly CurrentSite $site,
        private readonly FileTypePolicy $types,
        private readonly UploadLimits $limits,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * @throws FileRejected when the upload is too large, of a denied type,
     *                      or didn't arrive intact
     */
    public function store(UploadedFile $upload, string $folder = '', ?int $uploadedBy = null): File
    {
        $checked = $this->inspect($upload);
        $folder = FolderPath::normalize($folder);
        $source = (string) $upload->getRealPath();

        [$width, $height] = $this->dimensions($source, $checked['mime']);

        $directory = $this->site->tenantId().'/'.now()->format('Y/m');
        $name = bin2hex(random_bytes(20)).'.'.$checked['extension'];
        try {
            $path = $this->disk()->putFileAs($directory, $upload, $name);
        } catch (Throwable $e) {
            // Usually a full disk quota or an unwritable folder on a shared
            // host: logged for the admin, explained to the uploader.
            report($e);
            $path = false;
        }

        if ($path === false) {
            throw FileRejected::because('The server couldn\'t save this file. Its disk may be full or the storage folder not writable; the error log has details.');
        }

        try {
            return File::create([
                'folder' => $folder,
                'original_name' => $this->displayName($upload->getClientOriginalName(), $checked['extension']),
                'path' => $path,
                'mime' => $checked['mime'],
                'size' => (int) $upload->getSize(),
                'sha256' => (string) hash_file('sha256', $source),
                'width' => $width,
                'height' => $height,
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (Throwable $e) {
            // No row means nothing can reach the bytes, so don't leave them
            // eating the site's disk quota.
            $this->disk()->delete($path);

            throw $e;
        }
    }

    /**
     * Checks an upload without storing it, so validation can report every
     * problem before anything is written.
     *
     * @return array{mime: string, extension: string}
     *
     * @throws FileRejected
     */
    public function inspect(UploadedFile $upload): array
    {
        if (! $upload->isValid()) {
            throw FileRejected::because($this->uploadError($upload->getError()));
        }

        $max = $this->limits->maxFileBytes();

        if ((int) $upload->getSize() > $max) {
            throw FileRejected::because($this->tooLarge());
        }

        if (! extension_loaded('fileinfo')) {
            throw FileRejected::because('Uploads are off because this server can\'t check file types. Ask your host to enable the PHP fileinfo extension.');
        }

        $extension = strtolower($upload->getClientOriginalExtension());
        $detected = @(new finfo(FILEINFO_MIME_TYPE))->file((string) $upload->getRealPath());

        // Hosts with an upload virus scanner (Imunify360, ClamAV) may remove
        // the temporary file before PHP reads it.
        if ($detected === false) {
            throw FileRejected::because('The server couldn\'t read this upload. Your host\'s virus scanner may have removed it.');
        }

        return [
            'mime' => $this->types->check($extension, $detected),
            'extension' => $extension,
        ];
    }

    /**
     * Changes the display name. The extension stays, so a download still
     * opens in the right program; the stored name is never touched.
     */
    public function rename(File $file, string $name): File
    {
        $file->update(['original_name' => $this->displayName($name, $file->extension())]);

        return $file;
    }

    public function move(File $file, string $folder): File
    {
        $file->update(['folder' => FolderPath::normalize($folder)]);

        return $file;
    }

    public function describe(File $file, ?string $alt): File
    {
        $alt = trim((string) $alt);
        $file->update(['alt' => $alt === '' ? null : $alt]);

        return $file;
    }

    /**
     * Moves a file to the trash: it stops being listed and served at once,
     * and its bytes go when files:purge-trash runs.
     */
    public function delete(File $file): void
    {
        $file->delete();
    }

    /**
     * Removes a file and its bytes for good.
     */
    public function purge(File $file): void
    {
        $this->disk()->delete($file->path);
        $file->forceDelete();
    }

    public function disk(): Filesystem
    {
        return $this->filesystems->disk(config()->string('files.disk'));
    }

    /**
     * The file's full path on the local files disk, for streaming.
     */
    public function absolutePath(File $file): string
    {
        return $this->disk()->path($file->path);
    }

    public function exists(File $file): bool
    {
        return $this->disk()->exists($file->path);
    }

    public function tooLarge(): string
    {
        $max = UploadLimits::human($this->limits->maxFileBytes());

        return $this->limits->bindingSetting() === 'files.max_size'
            ? "This file is larger than the {$max} StarSystem allows."
            : "This file is larger than your host allows ({$max}).";
    }

    /**
     * Keeps the uploader's name readable but safe to put in a header or a
     * page: no path, no control characters, at most 255 characters, and
     * always the stored extension.
     */
    private function displayName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name));

        $suffix = $extension === '' ? '' : '.'.$extension;

        if ($suffix !== '' && str_ends_with(strtolower($name), $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }

        $name = rtrim($name, '. ');

        return mb_substr($name === '' ? 'file' : $name, 0, 255 - strlen($suffix)).$suffix;
    }

    /**
     * getimagesize only reads the header, so this is cheap and needs no GD.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path, string $mime): array
    {
        if (! str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        $size = @getimagesize($path);

        return $size === false ? [null, null] : [$size[0] ?: null, $size[1] ?: null];
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->tooLarge(),
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted before it finished. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file was received.',
            default => "The server couldn't receive the upload (PHP upload error {$code}). Ask your host to check PHP's temporary upload folder and free disk space.",
        };
    }
}
