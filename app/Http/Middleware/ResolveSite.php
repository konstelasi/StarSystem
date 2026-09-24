<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the request's site from its Host.
 *
 * With multi-site off, every request resolves to the default site. With it
 * on, only hosts listed in a site's domains are accepted, so a forged Host
 * header can't reach a site.
 */
class ResolveSite
{
    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = config('starsystem.multisite')
            ? Site::forHost($request->getHost())
            : Site::find(config()->integer('starsystem.default_site'));

        abort_if($site === null, 404);

        $this->current->set($site);

        return $next($request);
    }
}
