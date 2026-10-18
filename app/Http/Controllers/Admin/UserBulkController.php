<?php

namespace App\Http\Controllers\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Changes the role of several people at once, or removes them.
 *
 * Each person is checked on their own by the same rule as one at a time. Whoever
 * the actor may not manage is skipped and counted, and the rest still go
 * through, so a mixed selection is not all or nothing.
 */
class UserBulkController extends Controller
{
    private const MOST = 100;

    public function __construct(
        private readonly SiteAccess $access,
        private readonly CurrentSite $site,
        private readonly ActivityLog $activity,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MOST],
            'ids.*' => ['integer'],
            'role_id' => ['required', 'integer', Rule::exists('ss_roles', 'id')->where(
                fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()),
            )],
        ]);

        $role = Role::query()->findOrFail($request->integer('role_id'));

        abort_unless($this->access->canAssign($actor, $role), 403);

        $ids = $this->ids($request);
        $members = SiteUser::query()->with(['user', 'role'])->whereIn('id', $ids)->get();
        $changed = 0;
        $skipped = count($ids) - $members->count();

        foreach ($members as $member) {
            if (! $this->access->mayManage($actor, $member)) {
                $skipped++;

                continue;
            }

            if ($member->role_id === $role->id) {
                continue;
            }

            $from = $member->role->name;
            $member->update(['role_id' => $role->id]);

            $this->activity->record(ActivityAction::USER_ROLE_CHANGED, $member, ['from' => $from, 'to' => $role->name]);
            $changed++;
        }

        $this->access->forget();

        return $this->done(
            $changed,
            $skipped,
            $changed === 1 ? "1 person is now {$role->name}." : "{$changed} people are now {$role->name}.",
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MOST],
            'ids.*' => ['integer'],
        ]);

        $ids = $this->ids($request);
        $members = SiteUser::query()->with(['user', 'role'])->whereIn('id', $ids)->get();
        $removed = 0;
        $skipped = count($ids) - $members->count();

        foreach ($members as $member) {
            if (! $this->access->mayManage($actor, $member)) {
                $skipped++;

                continue;
            }

            $member->delete();

            $this->activity->record(ActivityAction::USER_REMOVED, $member, ['role' => $member->role->name]);
            $removed++;
        }

        $this->access->forget();

        return $this->done(
            $removed,
            $skipped,
            $removed === 1 ? '1 person can no longer use this site.' : "{$removed} people can no longer use this site.",
        );
    }

    /**
     * @return list<int>
     */
    private function ids(Request $request): array
    {
        return array_values(array_unique(array_map(intval(...), $request->array('ids'))));
    }

    private function done(int $done, int $skipped, string $message): RedirectResponse
    {
        if ($done === 0 && $skipped === 0) {
            $message = 'Nothing needed changing.';
        }

        if ($skipped > 0) {
            $message .= $skipped === 1 ? ' 1 person could not be changed.' : " {$skipped} people could not be changed.";
        }

        Inertia::flash('toast', ['type' => $done === 0 && $skipped > 0 ? 'error' : 'success', 'message' => $message]);

        return back(fallback: route('admin.users.index'));
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
