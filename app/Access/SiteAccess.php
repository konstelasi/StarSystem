<?php

namespace App\Access;

use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;

/**
 * Answers one question: may this account do this on the current site?
 *
 * The install owner may do anything on every site. Everyone else holds the
 * abilities of the role their membership of the current site gives them, and
 * none at all on a site they don't belong to.
 *
 * A request asks many times, so the lookup runs once per account and site
 * and is remembered. Call forget() after changing a membership or a role
 * within the same request.
 */
class SiteAccess
{
    /** @var array<string, array{role: Role|null, abilities: list<string>, suspended: bool}> */
    private array $remembered = [];

    public function __construct(
        private readonly CurrentSite $site,
        private readonly AbilityRegistry $registry,
    ) {}

    /**
     * Whether the account holds the ability, or its own-entries form. That
     * makes this the question for a route, which can't know which entry is
     * coming. A page that does know the entry asks mayOnEntry() as well.
     */
    public function allows(User $user, string $ability): bool
    {
        if ($user->is_install_owner) {
            return true;
        }

        $held = $this->abilities($user);

        return in_array($ability, $held, true)
            || (isset(Ability::OWN[$ability]) && in_array(Ability::OWN[$ability], $held, true));
    }

    /**
     * Whether the account may do this to an entry whose author is $authorId:
     * it holds the ability for every entry, or holds its own-entries form and
     * made this one. An entry with no author is nobody's own.
     */
    public function mayOnEntry(User $user, string $ability, ?int $authorId): bool
    {
        if ($user->is_install_owner) {
            return true;
        }

        $held = $this->abilities($user);

        if (in_array($ability, $held, true)) {
            return true;
        }

        $own = Ability::OWN[$ability] ?? null;

        return $own !== null
            && $authorId !== null
            && $authorId === $user->id
            && in_array($own, $held, true);
    }

    /**
     * What the account holds counted the way handing out is: an ability for
     * every entry also stands for its own-entries form, so whoever may edit
     * all entries may give a role the right to edit only a person's own.
     *
     * @return list<string>
     */
    public function effective(User $user): array
    {
        $abilities = $this->abilities($user);

        foreach (Ability::OWN as $full => $own) {
            if (in_array($full, $abilities, true)) {
                $abilities[] = $own;
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * Which of $abilities the actor doesn't effectively hold.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public function lacks(User $actor, array $abilities): array
    {
        return $actor->is_install_owner ? [] : array_values(array_diff($abilities, $this->effective($actor)));
    }

    /**
     * Every ability the account holds on the current site.
     *
     * @return list<string>
     */
    public function abilities(User $user): array
    {
        if ($user->is_install_owner) {
            return $this->registry->all();
        }

        return $this->lookup($user)['abilities'];
    }

    /**
     * The role the account holds on the current site. Null for the install
     * owner without a membership, and for someone who isn't a member.
     */
    public function role(User $user): ?Role
    {
        return $this->lookup($user)['role'];
    }

    /**
     * Whether $actor may hand out $role: only a role whose every ability
     * the actor holds too. Without this, someone allowed to change roles
     * could make themselves, or a friend, an administrator.
     */
    public function canAssign(User $actor, Role $role): bool
    {
        // Only abilities that exist now count. A role may still hold one of a
        // module that is switched off, which nobody can use and so nobody lacks.
        return $this->lacks($actor, $this->registry->known($role->abilities())) === [];
    }

    /**
     * Whether the account may use the current site at all.
     */
    public function isMember(User $user): bool
    {
        return $user->is_install_owner || $this->lookup($user)['role'] !== null;
    }

    /**
     * Whether the account belongs to the current site but has been paused.
     * The install owner can't be paused.
     */
    public function isSuspended(User $user): bool
    {
        return ! $user->is_install_owner && $this->lookup($user)['suspended'];
    }

    /**
     * Whether the account has a membership of any other site. What an
     * administrator does to an account here must not reach into those sites,
     * where the account may hold a higher role than theirs.
     */
    public function belongsToOtherSites(User $user): bool
    {
        return SiteUser::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('tenant_id', '!=', $this->site->tenantId())
            ->exists();
    }

    /**
     * Whether $actor may change, pause or remove this membership. Never their
     * own, never the install owner's, and never one that holds a role with an
     * ability the actor lacks, since that would let them reach above
     * themselves.
     */
    public function mayManage(User $actor, SiteUser $member): bool
    {
        return $member->user_id !== $actor->id
            && ! $member->user->is_install_owner
            && $this->canAssign($actor, $member->role);
    }

    /**
     * Whether the account may sign in on the current site: it belongs here,
     * or it holds an invitation here, for an email it confirmed, that it has
     * yet to accept.
     */
    public function maySignIn(User $user): bool
    {
        if ($this->isMember($user)) {
            return true;
        }

        // A paused account is turned away, whatever invitation it still holds.
        if ($this->isSuspended($user)) {
            return false;
        }

        // A profile email can be changed without being confirmed, so an invitation
        // only opens the door for an address the account has confirmed.
        return $user->email_verified_at !== null && $this->site->has() && SiteInvite::query()
            ->where('email', $user->email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function forget(): void
    {
        $this->remembered = [];
    }

    /**
     * @return array{role: Role|null, abilities: list<string>, suspended: bool}
     */
    private function lookup(User $user): array
    {
        if (! $this->site->has()) {
            return ['role' => null, 'abilities' => [], 'suspended' => false];
        }

        $key = $this->site->tenantId().':'.$user->id;

        return $this->remembered[$key] ??= $this->load($user);
    }

    /**
     * @return array{role: Role|null, abilities: list<string>, suspended: bool}
     */
    private function load(User $user): array
    {
        $membership = SiteUser::query()->with('role')->where('user_id', $user->id)->first();

        if ($membership === null) {
            return ['role' => null, 'abilities' => [], 'suspended' => false];
        }

        // A paused membership keeps its role but holds no abilities and doesn't count as one.
        if ($membership->suspended_at !== null) {
            return ['role' => null, 'abilities' => [], 'suspended' => true];
        }

        return ['role' => $membership->role, 'abilities' => $this->registry->known($membership->role->abilities()), 'suspended' => false];
    }
}
