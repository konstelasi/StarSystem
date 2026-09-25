<script setup lang="ts">
import { computed, ref } from 'vue';
import CanvasFieldCard from '@/components/builder/CanvasFieldCard.vue';
import { positionText, useBuilderContext } from '@/components/builder/context';
import { useSortable } from '@/components/builder/sortable';
import { cn } from '@/lib/utils';

const props = defineProps<{
    /** Layout slot id, or null for the main area. */
    slotId: string | null;
    emptyText?: string;
}>();

const { builder, announce } = useBuilderContext();

const fields = computed(() => builder.fieldsIn(props.slotId));
const list = ref<HTMLElement | null>(null);

useSortable(list, {
    group: { name: 'fields', pull: true, put: ['fields'] },
    handle: '[data-field-handle]',
    onDrop: ({ id, to, index }) => {
        const slot = to === '' ? null : to;
        builder.moveField(id, slot, index);

        const field = builder.findField(id);
        const position = builder.positionOf(id);
        const place = builder.slots.value.find((choice) => choice.id === slot);

        if (field && position) {
            announce(
                `${field.label} moved to ${place?.label ?? 'the form'}, ${positionText(position)}.`,
            );
        }
    },
});
</script>

<template>
    <div
        ref="list"
        :data-list="slotId ?? ''"
        :class="
            cn(
                'relative grid min-h-16 content-start gap-2 rounded-lg',
                fields.length === 0 && 'border border-dashed bg-muted/30 p-2',
            )
        "
    >
        <CanvasFieldCard
            v-for="field in fields"
            :key="field.uuid"
            :field="field"
        />

        <div
            v-if="fields.length === 0"
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 px-4 text-center text-sm text-muted-foreground"
        >
            <slot name="empty">{{ emptyText ?? 'Drop fields here' }}</slot>
        </div>
    </div>
</template>
