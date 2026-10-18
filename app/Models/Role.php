<?php

namespace App\Models;

use App\Access\AbilityRegistry;
use App\Sites\CurrentSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A named set of abilities. The built-in roles (Administrator, Editor,
 * Viewer) have no site and are locked; a role made for one site carries that
 * site's id as tenant_id and exists only there.
 *
 * Not site-scoped by a global scope, because the built-in roles must be
 * visible on every site. Use available() to list what the current site may
 * pick from.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property bool $system
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Role extends Model
{
    public const ADMINISTRATOR = 'Administrator';

    public const EDITOR = 'Editor';

    public const VIEWER = 'Viewer';

    protected $table = 'ss_roles';

    protected $fillable = ['tenant_id', 'name', 'system'];

    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }

    /**
     * The roles the current site can use: the built-in ones and its own.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', app(CurrentSite::class)->tenantId()));
    }

    /**
     * Whether this is the built-in Administrator role, not a custom one that
     * happens to hold many abilities.
     */
    public function isAdministrator(): bool
    {
        return $this->system && $this->tenant_id === null && $this->name === self::ADMINISTRATOR;
    }

    public static function builtIn(string $name): self
    {
        return static::query()->whereNull('tenant_id')->where('name', $name)->firstOrFail();
    }

    /**
     * What the role holds. The built-in Administrator holds every ability
     * that exists, a module's included, so it needs no row for each. Any
     * other role holds what its rows say.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        if ($this->isAdministrator()) {
            $all = app(AbilityRegistry::class)->all();
            sort($all);

            return $all;
        }

        $names = DB::table('ss_role_abilities')->where('role_id', $this->id)->orderBy('ability')->pluck('ability')->all();

        return array_values(array_map(strval(...), $names));
    }

    /**
     * Replace the role's abilities with exactly these.
     *
     * @param  list<string>  $abilities
     */
    public function syncAbilities(array $abilities): void
    {
        DB::transaction(function () use ($abilities) {
            DB::table('ss_role_abilities')->where('role_id', $this->id)->delete();

            DB::table('ss_role_abilities')->insert(array_map(
                fn (string $ability) => ['role_id' => $this->id, 'ability' => $ability],
                array_values(array_unique($abilities)),
            ));
        });
    }
}
