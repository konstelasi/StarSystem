<?php

namespace App\Http\Controllers\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\SiteUser;
use App\Models\User;
use App\Security\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Signs a member out of every device, for a phone that was lost or an
 * account that may have been used by someone else.
 *
 * Sessions aren't tied to a site, so this ends the account's sessions on
 * every site it belongs to. To lock someone out of this site alone, pause
 * them instead.
 */
class UserSessionController extends Controller
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly Sessions $sessions,
        private readonly ActivityLog $activity,
    ) {}

    public function destroy(Request $request, SiteUser $member): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->access->mayManage($actor, $member), 403);

        if (! $this->sessions->available()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Sessions are not kept in the database on this install, so they can\'t be ended from here. Pause the person to lock them out of this site now.']);

            return back();
        }

        $ended = $this->sessions->endOthers($member->user);

        if ($ended > 0) {
            $this->activity->record(ActivityAction::USER_SIGNED_OUT, $member, ['count' => $ended]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($ended) {
                0 => "{$member->user->name} was not signed in anywhere.",
                1 => "{$member->user->name} was signed out of 1 device.",
                default => "{$member->user->name} was signed out of {$ended} devices.",
            },
        ]);

        return back();
    }
}
