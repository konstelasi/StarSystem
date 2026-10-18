<?php

namespace App\Http\Controllers\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Pauses and resumes a member's access to this site.
 *
 * A paused member keeps their role and their account, but is signed out on
 * their next click and can't sign in here until resumed. It is for when
 * someone should be locked out for a while without being removed and
 * invited all over again.
 */
class UserSuspensionController extends Controller
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly ActivityLog $activity,
    ) {}

    public function store(Request $request, SiteUser $member): RedirectResponse
    {
        abort_unless($this->access->mayManage($this->actor($request), $member), 403);

        if ($member->suspended_at === null) {
            $member->forceFill(['suspended_at' => now()])->save();
            $this->access->forget();

            $this->activity->record(ActivityAction::USER_SUSPENDED, $member);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->user->name} is paused. They are signed out here until you resume them."]);

        return back();
    }

    public function destroy(Request $request, SiteUser $member): RedirectResponse
    {
        abort_unless($this->access->mayManage($this->actor($request), $member), 403);

        if ($member->suspended_at !== null) {
            $member->forceFill(['suspended_at' => null])->save();
            $this->access->forget();

            $this->activity->record(ActivityAction::USER_RESUMED, $member);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->user->name} can use this site again."]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
