<?php

namespace App\Http\Middleware;

use App\Access\Invitations;
use App\Access\SiteAccess;
use App\Models\SiteUser;
use App\Models\User;
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
    /**
     * How often a member's last visit is written, in minutes.
     */
    private const SEEN_EVERY_MINUTES = 10;

    public function __construct(
        private readonly SiteAccess $access,
        private readonly Invitations $invitations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->access->isMember($user)) {
            $this->noteSeen($request, $user);

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

    /**
     * Records that the member is using the site, at most once in a while.
     * The session remembers when it last did, so most requests cost nothing,
     * and the write skips model events and timestamps on purpose.
     */
    private function noteSeen(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $last = $request->session()->get('member_seen_at');

        if (is_int($last) && $last > now()->subMinutes(self::SEEN_EVERY_MINUTES)->getTimestamp()) {
            return;
        }

        SiteUser::query()->where('user_id', $user->id)->toBase()->update(['last_seen_at' => now()]);

        $request->session()->put('member_seen_at', now()->getTimestamp());
    }
}
