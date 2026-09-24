<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { hookComponent } from '@/lib/hooks';
import type { HookSlotItem } from '@/lib/hooks';

/**
 * Renders what modules added to a view slot. Items come from the page's
 * `hookSlots` prop (HookRegistry::viewSlot() on the server), or `items`.
 */
const props = defineProps<{
    name: string;
    items?: HookSlotItem[];
}>();

const page = usePage();

const rendered = computed(() => {
    const slots = page.props.hookSlots as
        | Record<string, HookSlotItem[]>
        | undefined;

    return (props.items ?? slots?.[props.name] ?? []).flatMap((item, index) => {
        const component = hookComponent(item);

        if (!component) {
            return [];
        }

        // PHP sends an empty props array as [] rather than {}.
        const bound = Array.isArray(item.props) ? {} : item.props;

        return [{ key: `${index}:${item.component}`, component, bound }];
    });
});
</script>

<template>
    <component
        :is="entry.component"
        v-for="entry in rendered"
        :key="entry.key"
        v-bind="entry.bound"
    />
</template>
