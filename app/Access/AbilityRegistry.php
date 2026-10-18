<?php

namespace App\Access;

use App\Models\User;
use App\Modules\ModuleLoader;
use Illuminate\Support\Facades\Gate;

/**
 * Every ability a role can hold right now: StarSystem's own, which the code
 * lists in `Ability`, plus the ones the modules running in this request
 * declare in their manifests.
 *
 * A module's abilities exist only while it runs. When it is switched off its
 * abilities drop out of the list, the Roles page stops showing them and
 * nobody holds them, but the rows in `ss_role_abilities` stay, so switching
 * it back on gives each role back what it had.
 *
 * It asks the module loader each time instead of keeping the list, so it
 * always reflects the modules the loader has.
 */
class AbilityRegistry
{
    /**
     * The abilities by area, each with its label. StarSystem's areas come
     * first, then one for each module that adds abilities, named after it.
     * Where a module's ability would clash with one of StarSystem's own, the
     * core one wins.
     *
     * @return array<string, array<string, string>>
     */
    public function groups(): array
    {
        $groups = Ability::groups();
        $core = array_flip(Ability::all());

        foreach (app(ModuleLoader::class)->loaded() as $manifest) {
            $abilities = array_diff_key($manifest->qualifiedAbilities(), $core);

            if ($abilities === []) {
                continue;
            }

            $name = array_key_exists($manifest->name, $groups) ? "{$manifest->name} module" : $manifest->name;
            $groups[$name] = $abilities;
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys(array_merge(...array_values($this->groups())));
    }

    public function exists(string $ability): bool
    {
        return in_array($ability, $this->all(), true);
    }

    /**
     * The label for an ability, or the ability itself when it isn't one
     * running now, such as a module's that has been switched off.
     */
    public function label(string $ability): string
    {
        return array_merge(...array_values($this->groups()))[$ability] ?? $ability;
    }

    /**
     * Only the abilities of $abilities that exist now.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public function known(array $abilities): array
    {
        return array_values(array_intersect($abilities, $this->all()));
    }

    /**
     * One gate per ability, so a route can say `can:forms.view` and code can
     * ask `$user->can('forms.view')`. The optional model is for per-model
     * permissions and is ignored until then.
     */
    public function defineGates(): void
    {
        foreach ($this->all() as $ability) {
            Gate::define($ability, fn (User $user, mixed $model = null) => app(SiteAccess::class)->allows($user, $ability));
        }
    }
}
