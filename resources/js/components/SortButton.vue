<script setup lang="ts">
import { ArrowDown, ArrowUp } from '@lucide/vue';
import { computed } from 'vue';

/**
 * A column heading that sorts its column. The sort is a name, with a minus
 * in front for descending, such as "seen" and "-seen". A first click sorts
 * ascending and a second one flips it.
 */
const props = defineProps<{
    label: string;
    /** The column's sort name, without a minus. */
    column: string;
    /** The sort in force. */
    sort: string;
}>();

const emit = defineEmits<{ sort: [sort: string] }>();

const direction = computed(() => {
    if (props.sort === props.column) {
        return 'asc';
    }

    return props.sort === `-${props.column}` ? 'desc' : null;
});

const next = computed(() =>
    direction.value === 'asc' ? `-${props.column}` : props.column,
);
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-1 font-[inherit] hover:text-foreground"
        :aria-label="`Sort by ${label}`"
        @click="emit('sort', next)"
    >
        {{ label }}
        <ArrowUp v-if="direction === 'asc'" class="size-3.5" />
        <ArrowDown v-else-if="direction === 'desc'" class="size-3.5" />
    </button>
</template>
