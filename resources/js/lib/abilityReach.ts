import type { RoleAbility } from '@/types/roles';

/** How far a role holds an ability that has an own form: not at all, for entries a person made, or for every entry. */
export type Reach = 'none' | 'own' | 'all';

export function reachOf(selected: string[], ability: RoleAbility): Reach {
    if (selected.includes(ability.key)) {
        return 'all';
    }

    return ability.own && selected.includes(ability.own.key) ? 'own' : 'none';
}

/** The selection with the ability held to the given reach, leaving every other ability as it was. */
export function withReach(
    selected: string[],
    ability: RoleAbility,
    level: Reach,
): string[] {
    if (!ability.own) {
        return selected;
    }

    const others = selected.filter(
        (key) => key !== ability.key && key !== ability.own?.key,
    );

    if (level === 'all') {
        return [...others, ability.key];
    }

    return level === 'own' ? [...others, ability.own.key] : others;
}

/** The selection with one ability ticked or unticked. */
export function withTicked(
    selected: string[],
    key: string,
    on: boolean,
): string[] {
    if (on) {
        return selected.includes(key) ? selected : [...selected, key];
    }

    return selected.filter((held) => held !== key);
}
