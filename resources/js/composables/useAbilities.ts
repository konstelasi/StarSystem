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

    const can = (ability: string) => abilities.value.has(ability);

    return { can, installOwner };
}
