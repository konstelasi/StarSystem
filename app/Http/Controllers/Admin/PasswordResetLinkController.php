<?php

namespace App\Http\Controllers\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Mail\MailSetup;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Throwable;

/**
 * Makes a password reset link for a member, for someone who has forgotten
 * theirs and can't ask for one themselves.
 *
 * A reset link lets whoever opens it sign in as the account, so it only
 * ever goes to the account's own inbox. Where mail doesn't work it is shown
 * once to the administrator to pass on, but only for an account that
 * belongs to this site alone. An account with access elsewhere may hold a
 * higher role there, and an administrator here must not be able to take
 * it over. That account's holder can use "Forgot password" themselves.
 */
class PasswordResetLinkController extends Controller
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly ActivityLog $activity,
    ) {}

    public function store(Request $request, SiteUser $member): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->access->mayManage($actor, $member), 403);

        $user = $member->user;
        $mail = MailSetup::configured();

        if (! $mail && $this->access->belongsToOtherSites($user)) {
            return $this->refuse("{$user->name} also belongs to another site, and email isn't set up here to send the link to them. They can use \"Forgot password\" on the sign-in page.");
        }

        $token = Password::broker()->createToken($user);

        if ($mail) {
            try {
                $user->sendPasswordResetNotification($token);
            } catch (Throwable $e) {
                report($e);
                Password::broker()->deleteToken($user);

                return $this->refuse('We could not send the email, so no link was made. Try again later.');
            }

            $this->activity->record(ActivityAction::USER_RESET_LINK, $member, ['emailed' => true]);

            Inertia::flash('toast', ['type' => 'success', 'message' => "We emailed {$user->name} a link to choose a new password."]);

            return back();
        }

        $this->activity->record(ActivityAction::USER_RESET_LINK, $member, ['emailed' => false]);

        Inertia::flash('reset', [
            'name' => $user->name,
            'email' => $user->email,
            'link' => route('password.reset', ['token' => $token, 'email' => $user->email]),
            'minutes' => config()->integer('auth.passwords.users.expire'),
        ]);

        return back();
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
