<script setup lang="ts">
import {
    Columns2,
    GripVertical,
    PanelTop,
    RectangleHorizontal,
} from '@lucide/vue';
import { computed, nextTick } from 'vue';
import CanvasFieldList from '@/components/builder/CanvasFieldList.vue';
import { positionText, useBuilderContext } from '@/components/builder/context';
import { blockKindLabel, blockName, slotName } from '@/lib/modelSchema';
import { cn } from '@/lib/utils';
import type { LayoutBlock } from '@/types/schema';

const props = defineProps<{ block: LayoutBlock }>();

const { builder, announce } = useBuilderContext();

const icon = computed(
    () =>
        ({
            section: RectangleHorizontal,
            tabs: PanelTop,
            columns: Columns2,
        })[props.block.kind],
);
const selected = computed(
    () =>
        builder.selection.value?.kind === 'block' &&
        builder.selection.value.id === props.block.id,
);

function select() {
    builder.select({ kind: 'block', id: props.block.id });
}

async function onHandleKey(event: KeyboardEvent) {
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
        return;
    }

    event.preventDefault();
    const next = builder.moveBlockBy(
        props.block.id,
        event.key === 'ArrowUp' ? -1 : 1,
    );

    if (next !== null) {
        announce(`${blockName(props.block)} moved to ${positionText(next)}.`);
        await nextTick();
        document
            .querySelector<HTMLElement>(
                `[data-block-handle="${props.block.id}"]`,
            )
            ?.focus();
    }
}
</script>

<template>
    <section
        data-sortable-item
        :data-id="block.id"
        :class="
            cn(
                'rounded-xl border-2 border-dashed bg-muted/20 transition-colors',
                selected
                    ? 'border-primary/70'
                    : 'border-border hover:border-foreground/20',
                '[&.sortable-ghost]:opacity-50',
            )
        "
        :aria-label="`${blockKindLabel(block.kind)}: ${blockName(block)}`"
        @click.self="select"
    >
        <header class="flex items-center gap-1 px-1.5 pt-1.5" @click="select">
            <button
                type="button"
                :data-block-handle="block.id"
                class="flex size-7 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none active:cursor-grabbing"
                :aria-label="`Move ${blockName(block)}. Use the up and down arrow keys, or drag.`"
                @keydown="onHandleKey"
            >
                <GripVertical class="size-4" aria-hidden="true" />
            </button>
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2 rounded px-1 text-left text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-pressed="selected"
                :aria-label="`${blockName(block)}, ${blockKindLabel(block.kind)}. Edit settings.`"
            >
                <component
                    :is="icon"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="truncate font-medium">{{ blockName(block) }}</span>
                <span v-if="block.label" class="text-xs text-muted-foreground">
                    {{ blockKindLabel(block.kind) }}
                </span>
            </button>
        </header>

        <div
            :class="
                cn(
                    'grid gap-3 p-3',
                    block.kind === 'columns' &&
                        '@xl:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]',
                )
            "
        >
            <div
                v-for="(slot, index) in block.slots"
                :key="slot.id"
                class="grid min-w-0 gap-1.5"
            >
                <p
                    v-if="block.kind !== 'section'"
                    class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    <PanelTop
                        v-if="block.kind === 'tabs'"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ slotName(block, index) }}
                </p>
                <CanvasFieldList :slot-id="slot.id" />
            </div>
        </div>
    </section>
</template>
