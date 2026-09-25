<?php

namespace App\Install\Http;

use App\Install\Installation;
use App\Install\Requirements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the installer only while StarSystem isn't installed, and makes
 * its requests safe to serve with no .env and no APP_KEY.
 *
 * The installer routes sit outside the web group, so nothing encrypts
 * cookies or starts a session. Inertia still reaches for the session, so
 * it gets the in-memory array driver, and the cache likewise, because the
 * configured database drivers may point at a server that doesn't exist
 * yet.
 */
class InstallerGate
{
    public function __construct(
        private readonly Installation $installation,
        private readonly Requirements $requirements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->installation->installed(), 404);

        config(['session.driver' => 'array', 'cache.default' => 'array']);

        if (($problem = $this->cannotRender()) !== null) {
            return $this->plainPage($problem);
        }

        return $next($request);
    }

    /**
     * The installer's own pages need compiled views and the built
     * front-end, so when either is missing it says so in plain HTML.
     */
    private function cannotRender(): ?string
    {
        if (! is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot'))) {
            return 'The "public/build" folder is missing. Upload every file from the StarSystem zip, including the "public" folder, then reload this page.';
        }

        $views = storage_path('framework/views');

        if (! is_dir($views) || ! is_writable($views)) {
            $failed = collect($this->requirements->checks())->reject(fn (array $check) => $check['ok']);

            return $failed->pluck('help')->filter()->implode("\n") ?: 'The "storage" folder can\'t be written to.';
        }

        return null;
    }

    private function plainPage(string $problem): Response
    {
        $items = collect(explode("\n", $problem))->map(fn (string $line) => '<li>'.e($line).'</li>')->implode('');

        return response(<<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install StarSystem</title></head>
            <body style="font-family: system-ui, sans-serif; max-width: 40rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.5">
            <h1 style="font-size: 1.25rem">StarSystem can't start the installer yet</h1>
            <ul>{$items}</ul>
            <p>Fix the above, then reload this page.</p>
            </body>
            </html>
            HTML, 503);
    }
}
