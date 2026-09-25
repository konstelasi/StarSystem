<?php

namespace App\Files;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Serves uploads through PHP instead of a public folder. Many shared hosts
 * block the symlink `storage:link` needs, and serving here lets every
 * response carry headers that stop an upload from acting as a page of
 * this site.
 *
 * BinaryFileResponse streams in small chunks (no file is read into memory),
 * answers Range requests so audio and video can seek, and never uses
 * X-Sendfile unless a proxy is explicitly trusted for it, which this
 * install doesn't do.
 */
class FileServer
{
    /**
     * Sandboxed, so even a file that slipped past the type policy can't run
     * script under this site's origin. Images, audio and video still load
     * because they're the document itself.
     */
    public const CSP = "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox";

    /**
     * Browsers' PDF viewers are plugins, which sandboxed documents may not
     * load, so a PDF gets the same lock-down without `sandbox`. PDF scripts
     * run inside the viewer, never in this site's origin.
     */
    public const PDF_CSP = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'";

    public function __construct(private readonly FileStore $store) {}

    /**
     * The one place that decides who may fetch a file. Every file is public
     * for now; private files would check $request->user() here, which also
     * means adding the session middleware to the serving route.
     */
    public function canServe(File $file, Request $request): bool
    {
        return true;
    }

    public function respond(File $file, Request $request): BinaryFileResponse
    {
        $path = $this->store->absolutePath($file);

        abort_unless(is_file($path), 404);

        $response = new BinaryFileResponse($path, 200, [], public: true, autoEtag: false, autoLastModified: false);

        $response->headers->set('Content-Type', $file->mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', $file->mime === 'application/pdf' ? self::PDF_CSP : self::CSP);
        $response->headers->set('Content-Disposition', $this->disposition($file));

        // The bytes under a uuid never change, so their hash is a strong
        // validator and revalidation is a cheap 304.
        $response->setEtag($file->sha256);
        $response->setLastModified($file->created_at);
        $response->setMaxAge(config()->integer('files.cache_seconds'));

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Inline for what browsers display safely themselves; everything else
     * downloads rather than opening under this site.
     */
    public function isInline(File $file): bool
    {
        return in_array($file->kind(), [FileKind::IMAGE, FileKind::VIDEO, FileKind::AUDIO], true)
            || $file->mime === 'application/pdf';
    }

    private function disposition(File $file): string
    {
        $name = str_replace(['/', '\\'], '_', $file->original_name);
        $fallback = (string) preg_replace('/[^\x20-\x7E]|[%"\\\\\/]/', '_', Str::ascii($name));

        return HeaderUtils::makeDisposition(
            $this->isInline($file) ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $name,
            $fallback === '' ? 'file' : $fallback,
        );
    }
}
