<?php

namespace App\Http\Controllers\Admin;

use App\Access\Ability;
use App\Access\Invitations;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Activity\ActivityRow;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Security\Sessions;
use App\Sites\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
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
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly SiteAccess $access,
        private readonly CurrentSite $site,
        private readonly Invitations $invitations,
        private readonly ActivityLog $activity,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $filters = $this->filters($request);
        $filtered = $filters['q'] !== null || $filters['role'] !== null || $filters['status'] !== null;

        $page = $this->query($filters)->paginate(self::PAGE_SIZE);

        $rows = $page->getCollection()->map(fn (SiteUser $member) => [
            'id' => $member->id,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'role' => ['id' => $member->role->id, 'name' => $member->role->name],
            'isYou' => $member->user_id === $actor->id,
            'isOwner' => $member->user->is_install_owner,
            'twoFactor' => $member->user->hasEnabledTwoFactorAuthentication(),
            'lastSeenAt' => $member->last_seen_at?->toIso8601String(),
            'joinedAt' => $member->created_at?->toIso8601String(),
            'suspended' => $member->suspended_at !== null,
            // What the page may offer for this row. The server decides again
            // when the action arrives.
            'editable' => $this->mayChange($actor, $member),
        ])->values();

        // The install owner uses every site without a membership, so they
        // are listed even where they have no row. Only at the top of an
        // unfiltered list, since they belong to no page of members.
        $owners = $filtered || $page->currentPage() > 1
            ? collect()
            : User::query()
                ->where('is_install_owner', true)
                ->whereNotIn('id', SiteUser::query()->select('user_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (User $owner) => [
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'isYou' => $owner->id === $actor->id,
                    'twoFactor' => $owner->hasEnabledTwoFactorAuthentication(),
                ]);

        $invites = SiteInvite::query()->with('role')->whereNull('accepted_at')->orderBy('email')->get()
            ->map(fn (SiteInvite $invite) => [
                'id' => $invite->id,
                'email' => $invite->email,
                'role' => $invite->role->name,
                'expiresAt' => $invite->expires_at->toIso8601String(),
                'expired' => $invite->expires_at->isPast(),
                'manageable' => $this->access->canAssign($actor, $invite->role),
            ])->values();

        return Inertia::render('admin/users/Index', [
            'members' => $rows,
            'owners' => $owners->values(),
            'filters' => $filters,
            'pagination' => [
                'page' => $page->currentPage(),
                'pages' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem() ?? 0,
                'to' => $page->lastItem() ?? 0,
            ],
            'invites' => $invites,
            'mailConfigured' => $this->invitations->mailConfigured(),
            // Who the install owner may hand ownership to: the other
            // Administrators of this site. Nobody else sees the list.
            'candidates' => $actor->is_install_owner ? $this->candidates($actor) : [],
            'roles' => Role::available()->orderBy('id')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'assignable' => $this->access->canAssign($actor, $role),
            ])->values(),
        ]);
    }

    /**
     * One person: who they are here, what they can be asked to do, and what
     * the log says about them.
     */
    public function show(Request $request, SiteUser $member, Sessions $sessions): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $member->load(['user', 'role']);
        $user = $member->user;

        // Who invited them, from the invitation they took up.
        $invitation = SiteInvite::query()
            ->with('inviter')
            ->where('email', $user->email)
            ->whereNotNull('accepted_at')
            ->latest('accepted_at')
            ->first();

        return Inertia::render('admin/users/Show', [
            'member' => [
                'id' => $member->id,
                'userId' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => ['id' => $member->role->id, 'name' => $member->role->name],
                'isYou' => $member->user_id === $actor->id,
                'isOwner' => $user->is_install_owner,
                'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
                'lastSeenAt' => $member->last_seen_at?->toIso8601String(),
                'joinedAt' => $member->created_at?->toIso8601String(),
                'suspended' => $member->suspended_at !== null,
                'editable' => $this->mayChange($actor, $member),
                'invitedBy' => $invitation?->inviter?->name,
            ],
            // How many devices they are signed in on, or null where sessions
            // aren't kept in the database and there is no way to know.
            'devices' => $sessions->available() ? count($sessions->for($user, '')) : null,
            // Their recent doings and what was done to them, for those who
            // may read the log.
            'activity' => $actor->can(Ability::ACTIVITY_VIEW) ? $this->recent($user) : null,
            'mailConfigured' => $this->invitations->mailConfigured(),
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

        $from = $member->role->name;
        $changed = $member->role_id !== $role->id;

        $member->update(['role_id' => $role->id]);
        $this->access->forget();

        if ($changed) {
            $this->activity->record(ActivityAction::USER_ROLE_CHANGED, $member, ['from' => $from, 'to' => $role->name]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->user->name} is now {$role->name}."]);

        // Back to the list or to the person's page, whichever it came from.
        return back(fallback: route('admin.users.index'));
    }

    public function destroy(Request $request, SiteUser $member): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($this->mayChange($actor, $member), 403);

        $member->delete();
        $this->access->forget();

        $this->activity->record(ActivityAction::USER_REMOVED, $member, ['role' => $member->role->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->user->name} can no longer use this site."]);

        return to_route('admin.users.index');
    }

    private function mayChange(User $actor, SiteUser $member): bool
    {
        return $this->access->mayManage($actor, $member);
    }

    /**
     * The search, role and status filters and the sort, as asked for in the
     * address. Anything that makes no sense is ignored, because it comes from
     * a link and there is nothing to correct.
     *
     * @return array{q: string|null, role: int|null, status: string|null, sort: string}
     */
    private function filters(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $sort = $request->query('sort');

        return [
            'q' => $q === '' ? null : mb_substr($q, 0, 100),
            'role' => $request->integer('role') ?: null,
            'status' => in_array($status, ['active', 'paused', 'no-two-factor'], true) ? $status : null,
            'sort' => in_array($sort, ['name', '-name', 'joined', '-joined', 'seen', '-seen'], true) ? $sort : 'name',
        ];
    }

    /**
     * @param  array{q: string|null, role: int|null, status: string|null, sort: string}  $filters
     * @return Builder<SiteUser>
     */
    private function query(array $filters): Builder
    {
        $query = SiteUser::query()
            ->select('ss_site_users.*')
            ->join('users', 'users.id', '=', 'ss_site_users.user_id')
            ->with(['user', 'role']);

        if ($filters['q'] !== null) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $inner) => $inner->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like));
        }

        if ($filters['role'] !== null) {
            $query->where('ss_site_users.role_id', $filters['role']);
        }

        match ($filters['status']) {
            'active' => $query->whereNull('ss_site_users.suspended_at'),
            'paused' => $query->whereNotNull('ss_site_users.suspended_at'),
            'no-two-factor' => $query->whereNull('users.two_factor_confirmed_at'),
            default => null,
        };

        $direction = str_starts_with($filters['sort'], '-') ? 'desc' : 'asc';
        $column = match (ltrim($filters['sort'], '-')) {
            'joined' => 'ss_site_users.created_at',
            'seen' => 'ss_site_users.last_seen_at',
            default => 'users.name',
        };

        return $query->orderBy($column, $direction)->orderBy('users.name')->orderBy('ss_site_users.id');
    }

    /**
     * The other Administrators of this site, for the owner to pick from.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    private function candidates(User $actor): array
    {
        $members = SiteUser::query()->with(['user', 'role'])->where('user_id', '!=', $actor->id)->get();

        return array_values($members
            ->filter(fn (SiteUser $member) => $member->role->isAdministrator())
            ->sortBy(fn (SiteUser $member) => mb_strtolower($member->user->name))
            ->map(fn (SiteUser $member) => ['id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email])
            ->all());
    }

    /**
     * The last rows the log holds about this account, as the one who did
     * something or the one it was done to.
     *
     * @return list<array{id: int, at: string, actor: string|null, action: string, label: string, subject: string|null, details: list<string>, ip: string|null}>
     */
    private function recent(User $user): array
    {
        $rows = Activity::query()
            ->where(fn (Builder $query) => $query
                ->where('actor_id', $user->id)
                ->orWhere(fn (Builder $inner) => $inner->where('subject_type', 'user')->where('subject_id', $user->id)))
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $recent = [];

        foreach ($rows as $row) {
            $recent[] = ActivityRow::from($row);
        }

        return $recent;
    }
}
