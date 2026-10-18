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
 * Where mail works the link is only emailed, so the administrator never
 * sees something that would let them sign in as the member. Where it
 * doesn't, the link is shown once for them to pass on, like an invitation.
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
        $token = Password::broker()->createToken($user);
        $emailed = false;

        if (MailSetup::configured()) {
            try {
                $user->sendPasswordResetNotification($token);
                $emailed = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->activity->record(ActivityAction::USER_RESET_LINK, $member, ['emailed' => $emailed]);

        if ($emailed) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "We emailed {$user->name} a link to choose a new password."]);

            return back();
        }

        Inertia::flash('reset', [
            'name' => $user->name,
            'email' => $user->email,
            'link' => route('password.reset', ['token' => $token, 'email' => $user->email]),
            'mailConfigured' => MailSetup::configured(),
            'minutes' => config()->integer('auth.passwords.users.expire'),
        ]);

        return back();
    }
}
