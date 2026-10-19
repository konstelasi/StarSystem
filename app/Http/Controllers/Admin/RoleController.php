<?php

namespace App\Http\Controllers\Admin;

use App\Access\Ability;
use App\Access\AbilityRegistry;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Security\TwoFactorRequirement;
use App\Sites\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $grants = $this->modelGrantCounts($roles);

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'system' => $role->system,
                'people' => (int) ($people[$role->id] ?? 0),
                'abilities' => $counts[$role->id] ?? 0,
                // How many models it holds something for, beyond the above.
                'models' => $grants[$role->id] ?? 0,
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
        $role->syncModelAbilities($data['models'] ?? []);

        $this->activity->record(ActivityAction::ROLE_CREATED, $role, array_filter([
            'abilities' => $data['abilities'],
            'models' => $this->modelChanges([], $role->modelAbilities()),
        ], fn ($value) => $value !== []));

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
        $modelsBefore = $role->modelAbilities();

        // A module that is switched off hides its abilities, and saving must
        // not lose them, or switching it back on would find the role empty.
        $dormant = array_values(array_diff($held, $this->registry->all()));

        $role->update(['name' => $data['name']]);
        $role->syncAbilities([...$data['abilities'], ...$dormant]);

        // A form that sends no models leaves the grants as they are.
        if ($data['models'] !== null) {
            $role->syncModelAbilities($data['models']);
        }

        $this->access->forget();

        $added = array_values(array_diff($data['abilities'], $before));
        $removed = array_values(array_diff($before, $data['abilities']));

        $models = $this->modelChanges($modelsBefore, $role->modelAbilities());

        if ($added !== [] || $removed !== [] || $models !== [] || $oldName !== $role->name) {
            $this->activity->record(ActivityAction::ROLE_UPDATED, $role, array_filter([
                'renamed_from' => $oldName !== $role->name ? $oldName : null,
                'added' => $added,
                'removed' => $removed,
                'models' => $models,
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
        $copy->syncModelAbilities($role->modelAbilities());

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
        $models = SchemaModel::query()->where('status', SchemaModel::ACTIVE)->orderBy('group')->orderBy('label')->get(['id', 'label', 'group']);

        return Inertia::render('admin/roles/Form', [
            'role' => $role ? ['id' => $role->id, 'name' => $role->name, 'system' => $role->system] : null,
            'selected' => $selected,
            // A locked role is shown but not editable.
            'readonly' => $role !== null && ! $this->mayChange($actor, $role),
            'areas' => collect($this->registry->groups())->map(fn (array $abilities, string $area) => [
                'name' => $area,
                'abilities' => $this->rows($abilities, $held),
            ])->values(),
            'models' => $models->map(fn (SchemaModel $model) => [
                'id' => $model->id,
                'label' => $model->label,
                'group' => $model->group,
                // What the actor can give for this model, as for the areas.
                'held' => array_values(array_intersect($this->access->effective($actor, $model->id), Ability::modelScoped())),
            ])->values(),
            'modelRows' => $this->modelRows(),
            // An object, so a role with none stays `{}` in JSON.
            'modelSelected' => (object) ($role?->modelAbilities() ?? []),
        ]);
    }

    /**
     * The abilities a role can hold for one model, shaped like the rows of an
     * area, except that whether the actor holds them differs per model and
     * is sent with each model instead.
     *
     * @return list<array{key: string, label: string, own?: array{key: string, label: string}}>
     */
    private function modelRows(): array
    {
        $labels = [];

        foreach ($this->registry->groups() as $abilities) {
            foreach ($abilities as $key => $label) {
                if (in_array($key, Ability::modelScoped(), true)) {
                    $labels[$key] = $label;
                }
            }
        }

        return array_map(function (array $row) {
            $out = ['key' => $row['key'], 'label' => $row['label']];

            if (isset($row['own'])) {
                $out['own'] = ['key' => $row['own']['key'], 'label' => $row['own']['label']];
            }

            return $out;
        }, $this->rows($labels, []));
    }

    /**
     * The rows of one area on the role form. An ability that has an
     * own-entries form is one row carrying that form as `own`, which the page
     * shows as None, Own or All, and the own form has no row of its own.
     *
     * @param  array<string, string>  $abilities  Label by ability.
     * @param  list<string>  $held
     * @return list<array{key: string, label: string, held: bool, own?: array{key: string, label: string, held: bool}}>
     */
    private function rows(array $abilities, array $held): array
    {
        $ownForms = array_flip(Ability::OWN);
        $rows = [];

        foreach ($abilities as $key => $label) {
            if (isset($ownForms[$key]) && isset($abilities[$ownForms[$key]])) {
                continue;
            }

            $row = ['key' => $key, 'label' => $label, 'held' => in_array($key, $held, true)];
            $own = Ability::OWN[$key] ?? null;

            if ($own !== null && isset($abilities[$own])) {
                $row['own'] = ['key' => $own, 'label' => $abilities[$own], 'held' => in_array($own, $held, true)];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The models are null when the request names none, which means leave a
     * role's grants as they are.
     *
     * @return array{name: string, abilities: list<string>, models: array<int, list<string>>|null}
     */
    private function validated(Request $request, ?Role $role): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('ss_roles', 'name')
                ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $this->site->tenantId()))
                ->ignore($role?->id)],
            'abilities' => ['present', 'array'],
            'abilities.*' => ['string', Rule::in($this->registry->all())],
            'models' => ['sometimes', 'array'],
            'models.*' => ['array'],
            'models.*.*' => ['string', Rule::in(Ability::modelScoped())],
        ]);

        $abilities = array_values(array_unique($data['abilities']));

        if ($this->access->lacks($this->actor($request), $abilities) !== []) {
            throw ValidationException::withMessages(['abilities' => 'You can only give abilities you hold yourself.']);
        }

        return [
            'name' => trim($data['name']),
            'abilities' => $abilities,
            'models' => isset($data['models']) ? $this->modelGrants($this->actor($request), $data['models']) : null,
        ];
    }

    /**
     * The abilities asked for per model, each model one of this site's and
     * each ability one the actor holds for that model.
     *
     * @param  array<int|string, list<string>>  $asked
     * @return array<int, list<string>>
     */
    private function modelGrants(User $actor, array $asked): array
    {
        $known = SchemaModel::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $grants = [];

        foreach ($asked as $modelId => $abilities) {
            $abilities = array_values(array_unique($abilities));

            if ($abilities === []) {
                continue;
            }

            if (! ctype_digit((string) $modelId) || ! in_array((int) $modelId, $known, true)) {
                throw ValidationException::withMessages(['models' => 'One of those models is not on this site.']);
            }

            if ($this->access->lacks($actor, $abilities, (int) $modelId) !== []) {
                throw ValidationException::withMessages(['models' => 'You can only give abilities you hold yourself.']);
            }

            $grants[(int) $modelId] = $abilities;
        }

        return $grants;
    }

    /**
     * What changed between two sets of grants, by the model's label, for the
     * activity log: the abilities added and removed on each model.
     *
     * @param  array<int, list<string>>  $before
     * @param  array<int, list<string>>  $after
     * @return array<string, array{added?: list<string>, removed?: list<string>}>
     */
    private function modelChanges(array $before, array $after): array
    {
        $ids = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

        if ($ids === []) {
            return [];
        }

        $labels = SchemaModel::query()->whereIn('id', $ids)->pluck('label', 'id');
        $changes = [];

        foreach ($ids as $id) {
            $added = array_values(array_diff($after[$id] ?? [], $before[$id] ?? []));
            $removed = array_values(array_diff($before[$id] ?? [], $after[$id] ?? []));

            if ($added !== [] || $removed !== []) {
                $changes[(string) ($labels[$id] ?? "Model {$id}")] = array_filter(['added' => $added, 'removed' => $removed]);
            }
        }

        return $changes;
    }

    /**
     * How many models each role holds something for.
     *
     * @param  iterable<Role>  $roles
     * @return array<int, int>
     */
    private function modelGrantCounts(iterable $roles): array
    {
        $ids = collect($roles)->pluck('id')->all();

        /** @var array<int, int> */
        return DB::table('ss_role_model_abilities')
            ->selectRaw('role_id, count(distinct model_id) as total')
            ->whereIn('role_id', $ids)
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->map(fn ($total) => (int) $total)
            ->all();
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
