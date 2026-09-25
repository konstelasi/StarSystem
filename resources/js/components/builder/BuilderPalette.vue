<script setup lang="ts">
import { computed } from 'vue';
import { positionText, useBuilderContext } from '@/components/builder/context';
import PaletteList from '@/components/builder/PaletteList.vue';
import type { PaletteItem } from '@/components/builder/PaletteList.vue';
import type { SortableDrop } from '@/components/builder/sortable';
import { blockKindLabel } from '@/lib/modelSchema';
import type { FieldTypeDescriptor, LayoutBlock } from '@/types/schema';

const props = defineProps<{ fieldTypes: FieldTypeDescriptor[] }>();

const { builder, announce } = useBuilderContext();

const CATEGORIES: { key: FieldTypeDescriptor['category']; label: string }[] = [
    { key: 'basic', label: 'Text' },
    { key: 'choice', label: 'Choices' },
    { key: 'number', label: 'Numbers' },
    { key: 'date', label: 'Dates' },
    { key: 'rich', label: 'Rich content' },
    { key: 'media', label: 'Media' },
    { key: 'advanced', label: 'Advanced' },
];

const BLOCKS: PaletteItem[] = [
    {
        id: 'section',
        label: 'Section',
        icon: 'rectangle-horizontal',
        hint: 'A titled group of fields',
    },
    {
        id: 'tabs',
        label: 'Tabs',
        icon: 'panel-top',
        hint: 'Fields split across tabs',
    },
    {
        id: 'columns',
        label: 'Columns',
        icon: 'columns-2',
        hint: 'Fields side by side',
    },
];

const groups = computed(() =>
    CATEGORIES.map((category) => ({
        ...category,
        items: props.fieldTypes
            .filter((type) => type.category === category.key)
            .map((type): PaletteItem => ({
                id: type.key,
                label: type.label,
                icon: type.icon,
            })),
    })).filter((group) => group.items.length > 0),
);

function announceAdded(fieldUuid: string | undefined) {
    const field = fieldUuid ? builder.findField(fieldUuid) : undefined;
    const position = field ? builder.positionOf(field.uuid) : null;

    if (field && position) {
        announce(`Added ${field.label} at ${positionText(position)}.`);
    }
}

/**
 * Clicking (or pressing Enter on) a field type adds it below the selected
 * field, into the selected layout block, or at the end of the main area.
 */
function addByClick(typeKey: string) {
    const selected = builder.selectedField.value;
    const block = builder.selectedBlock.value;
    let slot: string | null = null;
    let index = Number.POSITIVE_INFINITY;

    if (selected !== undefined) {
        slot = selected.layout_slot;
        index = (builder.positionOf(selected.uuid)?.index ?? 0) + 1;
    } else if (block !== undefined) {
        slot = block.slots[0].id;
    }

    announceAdded(builder.addField(typeKey, slot, index)?.uuid);
}

function addByDrop({ id, to, index }: SortableDrop) {
    announceAdded(builder.addField(id, to === '' ? null : to, index)?.uuid);
}

function addBlock(kind: string, index?: number) {
    const block = builder.addBlock(kind as LayoutBlock['kind'], index);
    announce(`Added ${blockKindLabel(block.kind)}.`);
}
</script>

<template>
    <nav
        aria-label="Add to the form"
        class="grid gap-4 @3xl:grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] @5xl:grid-cols-1"
    >
        <p class="col-span-full text-xs text-muted-foreground">
            Drag onto the form, or click to add.
        </p>

        <section v-for="group in groups" :key="group.key" class="grid gap-1.5">
            <h3
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ group.label }}
            </h3>
            <PaletteList
                group="fields"
                :items="group.items"
                @add="addByClick"
                @drop="addByDrop"
            />
        </section>

        <section class="grid gap-1.5">
            <h3
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Layout
            </h3>
            <PaletteList
                group="blocks"
                :items="BLOCKS"
                @add="addBlock"
                @drop="(drop) => addBlock(drop.id, drop.index)"
            />
        </section>
    </nav>
</template>
