<?php

namespace App\Security;

use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\User;
use App\Sites\CurrentSite;

/**
 * Which roles on the current site must sign in with two-factor.
 *
 * The list of role ids sits in the site's settings, and not on the roles,
 * because the three built-in roles are locked and shared by every site.
 * The install owner counts as an Administrator, since that is what they are
 * on every site.
 */
class TwoFactorRequirement
{
    public function __construct(
        private readonly CurrentSite $site,
        private readonly SiteAccess $access,
    ) {}

    /**
     * @return list<int>
     */
    public function roleIds(): array
    {
        if (! $this->site->has()) {
            return [];
        }

        $ids = $this->site->get()->settings['two_factor_roles'] ?? [];

        return is_array($ids)
            ? array_values(array_unique(array_map(intval(...), array_filter($ids, is_numeric(...)))))
            : [];
    }

    /**
     * @param  list<int>  $ids
     */
    public function setRoleIds(array $ids): void
    {
        $site = $this->site->get();

        $site->update(['settings' => [...($site->settings ?? []), 'two_factor_roles' => array_values(array_unique($ids))]]);
    }

    /**
     * Whether the account's role here demands two-factor, by the saved list
     * or by $ids, a list not saved yet, to see what saving it would do.
     *
     * @param  list<int>|null  $ids
     */
    public function appliesTo(User $user, ?array $ids = null): bool
    {
        $ids ??= $this->roleIds();

        if ($ids === []) {
            return false;
        }

        $roleId = $user->is_install_owner
            ? Role::builtIn(Role::ADMINISTRATOR)->id
            : $this->access->role($user)?->id;

        return $roleId !== null && in_array($roleId, $ids, true);
    }

    /**
     * Whether the account is held to the requirement and hasn't met it.
     */
    public function blocks(User $user): bool
    {
        return $this->appliesTo($user) && ! $user->hasEnabledTwoFactorAuthentication();
    }
}
