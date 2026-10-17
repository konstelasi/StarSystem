<?php

namespace App\Http\Controllers\Settings;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Ends the account's other sessions, from the Security page, for someone who
 * left a browser signed in somewhere or thinks a session was taken.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly Sessions $sessions,
        private readonly ActivityLog $activity,
    ) {}

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        /** @var User $user */
        $user = $request->user();

        $ended = $this->sessions->endOthers($user, $request->session()->getId());

        if ($ended > 0) {
            $this->activity->record(ActivityAction::SESSIONS_ENDED, null, ['count' => $ended]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($ended) {
                0 => 'You were not signed in anywhere else.',
                1 => 'Signed out of 1 other device.',
                default => "Signed out of {$ended} other devices.",
            },
        ]);

        return back();
    }
}
