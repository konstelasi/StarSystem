<?php

namespace App\Http\Middleware;

use App\Mail\MailSetup;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as Verified;

/**
 * Laravel's `verified`, except that it stands down where mail doesn't work.
 * Nobody there could receive the confirmation link, so asking for it would
 * lock the account out, and many shared hosts have no outgoing mail.
 */
class EnsureEmailIsVerified extends Verified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        if (! MailSetup::configured()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
