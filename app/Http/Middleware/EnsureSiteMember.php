<?php

namespace App\Http\Middleware;

use App\Access\Invitations;
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
 * page asks them to sign in again. An account that holds an invitation here
 * is sent to it instead, since signing in to accept it is how they arrive.
 */
class EnsureSiteMember
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly Invitations $invitations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->access->isMember($user)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403);

        // Someone who signed in to accept an invitation goes back to it.
        $token = $request->session()->get('invitation_token');

        if (is_string($token) && $this->invitations->find($token)?->email === strtolower($user->email)) {
            return redirect()->route('invitations.show', $token);
        }

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You do not have access to this site.');
    }
}
