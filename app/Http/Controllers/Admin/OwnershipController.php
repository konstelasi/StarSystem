<?php

namespace App\Http\Controllers\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The install owner hands the installation to an Administrator of this site.
 *
 * The owner updates the program and installs modules for every site, and is
 * an implicit Administrator on each of them without a membership. So the old
 * owner gets a real Administrator membership on every site that lacks one,
 * and keeps the access they had.
 */
class OwnershipController extends Controller
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly ActivityLog $activity,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'email' => ['required', 'string'],
            'password' => ['required', 'string', 'current_password'],
        ]);

        // Scoped to this site, so another site's membership is a 404.
        $target = SiteUser::query()->with(['user', 'role'])->findOrFail($request->integer('member_id'));

        // Only the built-in Administrator role, since the owner's power
        // reaches past any one site.
        abort_if($target->user_id === $actor->id || ! $target->role->isAdministrator(), 403);

        if (mb_strtolower(trim($data['email'])) !== mb_strtolower($target->user->email)) {
            throw ValidationException::withMessages(['email' => 'Type their email exactly as it is shown.']);
        }

        DB::transaction(function () use ($actor, $target) {
            $actor->forceFill(['is_install_owner' => false])->save();
            $target->user->forceFill(['is_install_owner' => true])->save();

            $administrator = Role::builtIn(Role::ADMINISTRATOR)->id;

            foreach (Site::query()->pluck('id') as $siteId) {
                DB::table('ss_site_users')->insertOrIgnore([
                    'tenant_id' => $siteId,
                    'user_id' => $actor->id,
                    'role_id' => $administrator,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->access->forget();
        $this->activity->record(ActivityAction::OWNERSHIP_TRANSFERRED, $target);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$target->user->name} now owns this installation."]);

        return to_route('admin.users.index');
    }
}
