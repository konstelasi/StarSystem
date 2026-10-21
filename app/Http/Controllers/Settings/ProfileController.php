<?php

namespace App\Http\Controllers\Settings;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Mail\ConfirmEmailChange;
use App\Mail\EmailChangeNotice;
use App\Mail\MailSetup;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ActivityLog $activity,
        private readonly CurrentSite $site,
    ) {}

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'status' => $request->session()->get('status'),
            // The address a change is waiting on, until its link is opened.
            'pendingEmail' => $request->user()->pending_email,
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * The name changes at once. A new email needs the password, and where
     * mail works it only takes effect when the link sent to the new address
     * is opened. Where mail doesn't work there is no link to send, so it
     * takes effect at once and counts as unconfirmed.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = Arr::only($request->validated(), ['name', 'email']);

        $newEmail = $data['email'];
        $emailChanges = mb_strtolower($newEmail) !== mb_strtolower($user->email);

        $user->fill(['name' => $data['name']]);

        $changed = array_keys($user->getDirty());
        $props = [];

        if (! $emailChanges) {
            // Giving the address already held also cancels a waiting change.
            $user->pending_email = null;
        } elseif (! MailSetup::configured()) {
            $props = ['email_from' => $user->email, 'email_to' => $newEmail];
            $changed[] = 'email';
            $user->forceFill(['email' => $newEmail, 'email_verified_at' => null, 'pending_email' => null]);
        }

        $user->save();

        if ($changed !== []) {
            $this->activity->record(ActivityAction::ACCOUNT_UPDATED, null, ['changed' => $changed, ...$props]);
        }

        [$type, $message] = $emailChanges && MailSetup::configured()
            ? $this->askToConfirm($user, $newEmail)
            : ['success', __('Profile updated.')];

        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Nobody could update the program or add modules without an owner.
        if ($user->is_install_owner) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You own this installation. Hand ownership to another Administrator before you delete your account.']);

            return back();
        }

        // Before the account goes, while the row can still name it.
        $this->activity->record(ActivityAction::ACCOUNT_DELETED, null, [], $user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Keeps the new address aside and mails it a link, which makes the change.
     * The current address is told too, in case someone else asked. If the
     * link can't be sent nothing is kept, so there is no half-made change.
     *
     * @return array{0: string, 1: string} The toast type and message.
     */
    private function askToConfirm(User $user, string $newEmail): array
    {
        $user->forceFill(['pending_email' => $newEmail])->save();

        $link = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(ConfirmEmailChange::LIFETIME_MINUTES),
            ['user' => $user->id, 'hash' => ConfirmEmailChangeController::hash($newEmail)],
        );
        $site = $this->site->get()->name;

        try {
            Mail::to($newEmail)->send(new ConfirmEmailChange($site, $newEmail, $link));
        } catch (Throwable $e) {
            report($e);
            $user->forceFill(['pending_email' => null])->save();

            return ['error', 'We could not send the email, so nothing was changed. Try again later.'];
        }

        try {
            Mail::to($user->email)->send(new EmailChangeNotice($site, $newEmail));
        } catch (Throwable $e) {
            report($e);
        }

        $this->activity->record(ActivityAction::ACCOUNT_EMAIL_REQUESTED, null, ['email_to' => $newEmail]);

        return ['success', "We sent a link to {$newEmail}. Your email changes once you open it."];
    }
}
