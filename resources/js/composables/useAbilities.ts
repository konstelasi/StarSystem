import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Whether an account holds an ability, as the server shared it. With a model,
 * holding it for every model or for that one counts. Without, only holding it
 * for every model does.
 */
export function holds(
    abilities: readonly string[],
    byModel: Record<number, string[]> | undefined,
    ability: string,
    modelId?: number,
): boolean {
    return (
        abilities.includes(ability) ||
        (modelId !== undefined && (byModel?.[modelId] ?? []).includes(ability))
    );
}

/**
 * What the signed-in account may do on this site, as the server shared it.
 * Pages use it to leave out what the account can't use. It is only for
 * convenience: the server checks every action again.
 */
export function useAbilities() {
    const page = usePage();

    const abilities = computed(() => page.props.auth.abilities ?? []);
    const installOwner = computed(() => page.props.auth.installOwner === true);

    const can = (ability: string, modelId?: number) =>
        holds(
            abilities.value,
            page.props.auth.modelAbilities,
            ability,
            modelId,
        );

    return { can, installOwner };
}
