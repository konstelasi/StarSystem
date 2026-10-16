<?php

namespace App\Http\Controllers\Settings;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly ActivityLog $activity) {}

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        $changed = array_keys($request->user()->getDirty());
        $oldEmail = $request->user()->getOriginal('email');

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($changed !== []) {
            $this->activity->record(ActivityAction::ACCOUNT_UPDATED, null, [
                'changed' => $changed,
                ...(in_array('email', $changed, true) ? ['email_from' => $oldEmail, 'email_to' => $request->user()->email] : []),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

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
}
