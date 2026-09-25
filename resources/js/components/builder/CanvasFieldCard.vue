<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    Copy,
    EllipsisVertical,
    EyeOff,
    Filter,
    GripVertical,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick } from 'vue';
import { positionText, useBuilderContext } from '@/components/builder/context';
import FieldTypeIcon from '@/components/builder/FieldTypeIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { initialValue, rendererFor } from '@/fields';
import { cn } from '@/lib/utils';
import type { FieldSpecJson } from '@/types/schema';

const props = defineProps<{ field: FieldSpecJson }>();

const { builder, announce } = useBuilderContext();

const type = computed(() => builder.typeOf(props.field.type));
const selected = computed(
    () =>
        builder.selection.value?.kind === 'field' &&
        builder.selection.value.uuid === props.field.uuid,
);
const position = computed(() => builder.positionOf(props.field.uuid));
const isFirst = computed(() => position.value?.index === 0);
const isLast = computed(
    () =>
        position.value !== null &&
        position.value.index === position.value.total - 1,
);

function select() {
    builder.select({ kind: 'field', uuid: props.field.uuid });
}

async function move(delta: number) {
    const next = builder.moveFieldBy(props.field.uuid, delta);

    if (next === null) {
        return;
    }

    announce(`${props.field.label} moved to ${positionText(next)}.`);

    // Vue moves the element to its new place, which drops focus.
    await nextTick();
    document
        .querySelector<HTMLElement>(`[data-field-handle="${props.field.uuid}"]`)
        ?.focus();
}

function onHandleKey(event: KeyboardEvent) {
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
        void move(event.key === 'ArrowUp' ? -1 : 1);
    }
}

function duplicate() {
    const copy = builder.duplicateField(props.field.uuid);

    if (copy) {
        announce(`Added ${copy.label}.`);
    }
}

function remove() {
    const label = props.field.label;
    builder.removeField(props.field.uuid);
    announce(`Removed ${label}.`);
}
</script>

<template>
    <div
        data-sortable-item
        :data-id="field.uuid"
        :class="
            cn(
                'group/card rounded-lg border bg-card shadow-xs transition-[border-color,box-shadow]',
                selected
                    ? 'border-primary ring-3 ring-primary/15'
                    : 'hover:border-foreground/25',
                '[&.sortable-ghost]:border-dashed [&.sortable-ghost]:border-primary [&.sortable-ghost]:opacity-50',
            )
        "
        @click="select"
    >
        <div class="flex items-center gap-1 py-1.5 pr-1.5 pl-1">
            <button
                type="button"
                :data-field-handle="field.uuid"
                class="flex size-7 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none active:cursor-grabbing"
                :aria-label="`Move ${field.label}. Use the up and down arrow keys, or drag.`"
                @keydown="onHandleKey"
                @click.stop="select"
            >
                <GripVertical class="size-4" aria-hidden="true" />
            </button>

            <span
                class="flex size-6 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground [&_svg]:size-3.5"
                :title="type?.label ?? field.type"
            >
                <FieldTypeIcon :name="type?.icon" />
            </span>

            <button
                type="button"
                class="flex min-w-0 flex-1 items-baseline gap-2 rounded px-1 text-left focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-pressed="selected"
                :aria-label="`${field.label}, ${type?.label ?? field.type} field. Edit settings.`"
                @click.stop="select"
            >
                <span class="truncate text-sm font-medium">
                    {{ field.label }}
                    <span v-if="field.required" class="text-destructive"
                        >*</span
                    >
                </span>
                <code class="truncate text-xs text-muted-foreground">{{
                    field.key
                }}</code>
            </button>

            <span
                v-if="field.filterable"
                class="hidden shrink-0 items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px] text-muted-foreground @md:inline-flex"
                title="Filterable"
            >
                <Filter class="size-3" aria-hidden="true" />
                Filterable
            </span>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="size-7 shrink-0 text-muted-foreground"
                        :aria-label="`More actions for ${field.label}`"
                        @click.stop
                    >
                        <EllipsisVertical />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-44">
                    <DropdownMenuItem :disabled="isFirst" @select="move(-1)">
                        <ArrowUp /> Move up
                    </DropdownMenuItem>
                    <DropdownMenuItem :disabled="isLast" @select="move(1)">
                        <ArrowDown /> Move down
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="duplicate">
                        <Copy /> Duplicate
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" @select="remove">
                        <Trash2 /> Remove
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="border-t px-3 pt-2.5 pb-3">
            <p
                v-if="field.type === 'hidden'"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <EyeOff class="size-4" aria-hidden="true" />
                Hidden from editors. Stored with every entry.
            </p>
            <!-- A live, non-interactive rendering: what editors will see. -->
            <div v-else inert class="pointer-events-none select-none">
                <component
                    :is="rendererFor(field.type)"
                    :field="field"
                    :model-value="initialValue(field)"
                />
            </div>
        </div>
    </div>
</template>
