<script setup lang="ts">
import { ref } from 'vue';
import FieldTypeIcon from '@/components/builder/FieldTypeIcon.vue';
import { useSortable } from '@/components/builder/sortable';
import type { SortableDrop } from '@/components/builder/sortable';
import { cn } from '@/lib/utils';

export type PaletteItem = {
    id: string;
    label: string;
    icon: string;
    hint?: string;
};

const props = defineProps<{
    /** SortableJS group: `fields` items drop into slots, `blocks` into the layout. */
    group: 'fields' | 'blocks';
    items: PaletteItem[];
}>();

const emit = defineEmits<{
    add: [id: string];
    drop: [drop: SortableDrop];
}>();

const list = ref<HTMLElement | null>(null);

useSortable(list, {
    group: { name: props.group, pull: 'clone', put: false },
    sort: false,
    onDrop: (drop) => emit('drop', drop),
});
</script>

<template>
    <div
        ref="list"
        class="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-1.5"
    >
        <button
            v-for="item in items"
            :key="item.id"
            type="button"
            data-sortable-item
            :data-id="item.id"
            :class="
                cn(
                    'flex w-full cursor-grab items-center gap-2 rounded-md border bg-card px-2 py-1.5 text-left text-sm shadow-xs transition-colors hover:border-foreground/25 hover:bg-accent focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none active:cursor-grabbing',
                    '[&.sortable-ghost]:border-dashed [&.sortable-ghost]:border-primary [&.sortable-ghost]:bg-primary/5 [&.sortable-ghost]:shadow-none',
                    group === 'blocks' && 'border-dashed shadow-none',
                )
            "
            :title="item.hint ?? `Add ${item.label}`"
            @click="emit('add', item.id)"
        >
            <span
                class="flex size-6 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground [&_svg]:size-3.5"
            >
                <FieldTypeIcon :name="item.icon" />
            </span>
            <span class="truncate">{{ item.label }}</span>
        </button>
    </div>
</template>
