<?php

namespace App\Frontend;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Whether an address, one segment such as "blog", is already used by
 * StarSystem itself. A published model must not take over a page the admin,
 * the sign-in screens or a module need.
 *
 * Part of that is a fixed list, for folders and prefixes that may not have
 * a route of their own (like /build). The rest is asked of the router, so
 * the sign-in routes and any route a module adds are covered without a
 * list that goes stale.
 */
class AddressConflicts
{
    private const RESERVED = [
        'admin', 'api', 'install', 'settings', 'dashboard', '_system', '_modules', '_themes',
        'files', 'build', 'storage', 'vendor', 'up',
    ];

    public function __construct(private readonly Router $router) {}

    public function uses(string $path): bool
    {
        $path = trim($path, '/');

        if ($path === '') {
            return false;
        }

        return $this->reserved($path) || $this->routed('/'.$path) || $this->routed('/'.$path.'/x');
    }

    /**
     * Whether the address starts with one of the folders or prefixes that
     * StarSystem keeps for itself, such as "admin" or "files". The front end
     * answers these with the framework's plain 404 and never its own page.
     */
    public function reserved(string $path): bool
    {
        return in_array(strtolower(explode('/', trim($path, '/'))[0]), self::RESERVED, true);
    }

    /**
     * Whether a route other than the front end's catch-all has this address
     * under any method. The method is left out of the match because the
     * catch-all answers GET and HEAD for every address, so asking the router
     * for a POST would report all of them as taken.
     */
    private function routed(string $uri): bool
    {
        $request = Request::create($uri);

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $route->isFallback && $route->matches($request, false)) {
                return true;
            }
        }

        return false;
    }
}
