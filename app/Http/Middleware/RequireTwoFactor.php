<?php

namespace App\Http\Middleware;

use App\Security\TwoFactorRequirement;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds back an account whose role must use two-factor until it has turned
 * it on. It can still reach the page that turns it on, the pages that page
 * needs, and sign out.
 */
class RequireTwoFactor
{
    /**
     * Route names that stay open: the security settings, Fortify's
     * two-factor and password confirmation routes, and signing out.
     */
    private const OPEN = ['security.*', 'two-factor.*', 'password.confirm', 'password.confirm.store', 'password.confirmation', 'logout'];

    public function __construct(private readonly TwoFactorRequirement $requirement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No route means a 404 is coming, which there is nothing to hold back.
        if ($user === null || $request->route() === null || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        if (! $this->requirement->blocks($user)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403, 'Your role requires two-factor sign-in. Turn it on in your security settings.');

        Inertia::flash('toast', ['type' => 'error', 'message' => 'Your role requires two-factor sign-in. Turn it on to carry on.']);

        return redirect()->route('security.edit');
    }
}
