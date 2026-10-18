<?php

namespace App\Http\Controllers\Admin;

use App\Access\Ability;
use App\Access\AbilityRegistry;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Security\TwoFactorRequirement;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roles this site can give people. The three built-in roles are locked;
 * a role made here belongs to this site alone.
 *
 * Nobody can hand out more than they hold: a role may only be given
 * abilities its editor has, and only a role whose abilities the editor has
 * can be edited, deleted or copied.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly SiteAccess $access,
        private readonly CurrentSite $site,
        private readonly ActivityLog $activity,
        private readonly TwoFactorRequirement $twoFactor,
        private readonly AbilityRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $people = SiteUser::query()->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');
        $own = $this->access->role($actor)?->id;

        $roles = Role::available()->orderBy('id')->get();
        $counts = $this->abilityCounts($roles);

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'system' => $role->system,
                'people' => (int) ($people[$role->id] ?? 0),
                'abilities' => $counts[$role->id] ?? 0,
                'editable' => $this->mayChange($actor, $role),
                'duplicable' => $this->access->canAssign($actor, $role),
                'mine' => $role->id === $own,
            ])->values(),
            // Which roles must sign in with two-factor, and whether this
            // account may change that and has two-factor on itself.
            'twoFactor' => [
                'roles' => $this->twoFactor->roleIds(),
                'editable' => $actor->can(Ability::ROLES_UPDATE),
                'yours' => $actor->hasEnabledTwoFactorAuthentication(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $role = Role::create(['tenant_id' => $this->site->tenantId(), 'name' => $data['name'], 'system' => false]);
        $role->syncAbilities($data['abilities']);

        $this->activity->record(ActivityAction::ROLE_CREATED, $role, ['abilities' => $data['abilities']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$role->name} was created."]);

        return to_route('admin.roles.index');
    }

    public function edit(Request $request, Role $role): Response
    {
        return $this->form($request, $role);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->mayChange($this->actor($request), $role), 403);

        $data = $this->validated($request, $role);

        $oldName = $role->name;
        $held = $role->abilities();
        $before = $this->registry->known($held);

        // A module that is switched off hides its abilities, and saving must
        // not lose them, or switching it back on would find the role empty.
        $dormant = array_values(array_diff($held, $this->registry->all()));

        $role->update(['name' => $data['name']]);
        $role->syncAbilities([...$data['abilities'], ...$dormant]);
        $this->access->forget();

        $added = array_values(array_diff($data['abilities'], $before));
        $removed = array_values(array_diff($before, $data['abilities']));

        if ($added !== [] || $removed !== [] || $oldName !== $role->name) {
            $this->activity->record(ActivityAction::ROLE_UPDATED, $role, array_filter([
                'renamed_from' => $oldName !== $role->name ? $oldName : null,
                'added' => $added,
                'removed' => $removed,
            ]));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$role->name} was saved."]);

        return to_route('admin.roles.index');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->mayChange($this->actor($request), $role), 403);

        $inUse = SiteUser::withoutGlobalScopes()->where('role_id', $role->id)->exists()
            || SiteInvite::withoutGlobalScopes()->where('role_id', $role->id)->exists();

        if ($inUse) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$role->name} is still given to someone, or to an invitation. Change that first."]);

            return to_route('admin.roles.index');
        }

        $role->delete();

        $this->activity->record(ActivityAction::ROLE_DELETED, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$role->name} was deleted."]);

        return to_route('admin.roles.index');
    }

    /**
     * A copy to start from, which is how a built-in role becomes a custom one.
     */
    public function duplicate(Request $request, Role $role): RedirectResponse
    {
        abort_unless($this->access->canAssign($this->actor($request), $role), 403);

        $name = "Copy of {$role->name}";

        for ($n = 2; Role::available()->where('name', $name)->exists(); $n++) {
            $name = "Copy of {$role->name} {$n}";
        }

        $copy = Role::create(['tenant_id' => $this->site->tenantId(), 'name' => $name, 'system' => false]);
        $copy->syncAbilities($role->abilities());

        $this->activity->record(ActivityAction::ROLE_DUPLICATED, $copy, ['from' => $role->name]);

        return to_route('admin.roles.edit', $copy);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    /**
     * Whether $actor may edit or delete $role: never a built-in role, never
     * the one they hold themselves, and never one with an ability they lack.
     */
    private function mayChange(User $actor, Role $role): bool
    {
        return ! $role->system
            && $role->id !== $this->access->role($actor)?->id
            && $this->access->canAssign($actor, $role);
    }

    private function form(Request $request, ?Role $role): Response
    {
        $actor = $this->actor($request);
        // What the actor can give, so holding an ability for every entry also
        // lets them give its own-entries form.
        $held = $this->access->effective($actor);
        $selected = $this->registry->known($role?->abilities() ?? []);

        return Inertia::render('admin/roles/Form', [
            'role' => $role ? ['id' => $role->id, 'name' => $role->name, 'system' => $role->system] : null,
            'selected' => $selected,
            // A locked role is shown but not editable.
            'readonly' => $role !== null && ! $this->mayChange($actor, $role),
            'areas' => collect($this->registry->groups())->map(fn (array $abilities, string $area) => [
                'name' => $area,
                'abilities' => collect($abilities)->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'held' => in_array($key, $held, true),
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * @return array{name: string, abilities: list<string>}
     */
    private function validated(Request $request, ?Role $role): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('ss_roles', 'name')
                ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()))
                ->ignore($role?->id)],
            'abilities' => ['present', 'array'],
            'abilities.*' => ['string', Rule::in($this->registry->all())],
        ]);

        $abilities = array_values(array_unique($data['abilities']));

        if ($this->access->lacks($this->actor($request), $abilities) !== []) {
            throw ValidationException::withMessages(['abilities' => 'You can only give abilities you hold yourself.']);
        }

        return ['name' => trim($data['name']), 'abilities' => $abilities];
    }

    /**
     * @param  iterable<Role>  $roles
     * @return array<int, int>
     */
    private function abilityCounts(iterable $roles): array
    {
        $counts = [];

        foreach ($roles as $role) {
            $counts[$role->id] = count($this->registry->known($role->abilities()));
        }

        return $counts;
    }
}
