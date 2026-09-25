<script setup lang="ts">
import { MousePointerClick } from '@lucide/vue';
import { computed, ref } from 'vue';
import CanvasBlock from '@/components/builder/CanvasBlock.vue';
import CanvasFieldList from '@/components/builder/CanvasFieldList.vue';
import { positionText, useBuilderContext } from '@/components/builder/context';
import { useSortable } from '@/components/builder/sortable';
import { blockName } from '@/lib/modelSchema';
import { cn } from '@/lib/utils';

const { builder, announce } = useBuilderContext();

const layout = computed(() => builder.schema.value.layout);
const isEmpty = computed(
    () => builder.schema.value.fields.length === 0 && layout.value.length === 0,
);

const blockList = ref<HTMLElement | null>(null);

useSortable(blockList, {
    group: { name: 'blocks', pull: false, put: ['blocks'] },
    handle: '[data-block-handle]',
    onDrop: ({ id, index }) => {
        builder.moveBlock(id, index);

        const block = builder.findBlock(id);
        const position = layout.value.findIndex((item) => item.id === id);

        if (block) {
            announce(
                `${blockName(block)} moved to ${positionText({ index: position, total: layout.value.length })}.`,
            );
        }
    },
});
</script>

<template>
    <div class="grid gap-4" @click.self="builder.select(null)">
        <CanvasFieldList
            :slot-id="null"
            :class="isEmpty ? 'min-h-64' : undefined"
            empty-text="Drop fields here to show them above the layout"
        >
            <template v-if="isEmpty" #empty>
                <MousePointerClick class="size-8" aria-hidden="true" />
                <span class="font-medium text-foreground">
                    Start building your form
                </span>
                <span class="max-w-sm">
                    Drag a field from the list onto this area, or click a field
                    to add it. You can rearrange everything afterwards.
                </span>
            </template>
        </CanvasFieldList>

        <div
            ref="blockList"
            data-list="layout"
            :class="
                cn(
                    'relative grid gap-4',
                    layout.length === 0 &&
                        'min-h-14 rounded-xl border-2 border-dashed',
                )
            "
        >
            <CanvasBlock
                v-for="block in layout"
                :key="block.id"
                :block="block"
            />
            <p
                v-if="layout.length === 0"
                class="pointer-events-none absolute inset-0 flex items-center justify-center px-4 text-center text-sm text-muted-foreground"
            >
                Drag a section, tabs or columns here to group fields.
            </p>
        </div>
    </div>
</template>
