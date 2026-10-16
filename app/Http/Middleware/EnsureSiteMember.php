<?php

namespace App\Http\Middleware;

use App\Access\SiteAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away a signed-in account that doesn't belong to this site.
 *
 * Signing in already refuses such accounts, so this catches the ones that
 * were let in earlier: someone removed from the site while signed in, or a
 * session carried over to another site. They are signed out, so the next
 * page asks them to sign in again.
 */
class EnsureSiteMember
{
    public function __construct(private readonly SiteAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->access->isMember($user)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403);

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You do not have access to this site.');
    }
}
