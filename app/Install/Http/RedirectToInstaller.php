<?php

namespace App\Install\Http;

use App\Install\Installation;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every request to /install until StarSystem is installed.
 *
 * Without it, a fresh upload answers its first visitor with a
 * MissingAppKeyException from the cookie middleware. This runs globally,
 * before the web group, so no session or cookie code is touched first.
 */
class RedirectToInstaller
{
    public function __construct(private readonly Installation $installation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installation->installed() || $request->is('install', 'install/*')) {
            return $next($request);
        }

        return new RedirectResponse(url('/install'));
    }
}
