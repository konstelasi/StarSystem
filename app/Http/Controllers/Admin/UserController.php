<?php

namespace App\Http\Controllers\Admin;

use App\Access\SiteAccess;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who can use this site, and as what.
 *
 * Removing someone only ends their membership of this site. The account
 * stays, because it may belong to other sites.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly CurrentSite $site,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $members = SiteUser::query()->with(['user', 'role'])->get()->sortBy(fn (SiteUser $member) => mb_strtolower($member->user->name));

        $rows = $members->map(fn (SiteUser $member) => [
            'id' => $member->id,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'role' => ['id' => $member->role->id, 'name' => $member->role->name],
            'isYou' => $member->user_id === $actor->id,
            'isOwner' => $member->user->is_install_owner,
            // What the page may offer for this row. The server decides again
            // when the action arrives.
            'editable' => $this->mayChange($actor, $member),
        ])->values();

        // The install owner uses every site without a membership, so they
        // are listed even where they have no row.
        $owners = User::query()
            ->where('is_install_owner', true)
            ->whereNotIn('id', $members->pluck('user_id'))
            ->orderBy('name')
            ->get()
            ->map(fn (User $owner) => [
                'id' => null,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => null,
                'isYou' => $owner->id === $actor->id,
                'isOwner' => true,
                'editable' => false,
            ]);

        return Inertia::render('admin/users/Index', [
            'members' => $rows->concat($owners)->values(),
            'roles' => Role::available()->orderBy('id')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'assignable' => $this->access->canAssign($actor, $role),
            ])->values(),
        ]);
    }

    public function update(Request $request, SiteUser $member): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->mayChange($actor, $member), 403);

        $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('ss_roles', 'id')->where(
                fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()),
            )],
        ]);

        $role = Role::query()->findOrFail($request->integer('role_id'));

        abort_unless($this->access->canAssign($actor, $role), 403);

        $member->update(['role_id' => $role->id]);
        $this->access->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->user->name} is now {$role->name}."]);

        return to_route('admin.users.index');
    }

    public function destroy(Request $request, SiteUser $member): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->mayChange($actor, $member), 403);

        $member->delete();
        $this->access->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->user->name} can no longer use this site."]);

        return to_route('admin.users.index');
    }

    /**
     * Whether $actor may change or remove this membership. Never their own,
     * never the install owner's, and never one that holds a role with an
     * ability the actor lacks, since that would let them reach above
     * themselves.
     */
    private function mayChange(User $actor, SiteUser $member): bool
    {
        return $member->user_id !== $actor->id
            && ! $member->user->is_install_owner
            && $this->access->canAssign($actor, $member->role);
    }
}
