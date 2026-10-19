import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * What the signed-in account may do on this site, as the server shared it.
 * Pages use it to leave out what the account can't use. It is only for
 * convenience: the server checks every action again.
 */
export function useAbilities() {
    const page = usePage();

    const abilities = computed(
        () => new Set(page.props.auth.abilities ?? ([] as string[])),
    );
    const installOwner = computed(() => page.props.auth.installOwner === true);

    /**
     * With a model, whether the account holds the ability for every model or
     * for that one. Without, whether it holds it for every model.
     */
    const can = (ability: string, modelId?: number) =>
        abilities.value.has(ability) ||
        (modelId !== undefined &&
            (page.props.auth.modelAbilities?.[modelId] ?? []).includes(
                ability,
            ));

    return { can, installOwner };
}
