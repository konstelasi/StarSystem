<script setup lang="ts">
import { Upload } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';

/**
 * Wraps an area that accepts dropped files and shows a hint while files
 * are dragged over it. Call `choose()` (exposed) to open the file dialog.
 */
const props = defineProps<{
    disabled?: boolean;
    multiple?: boolean;
    label?: string;
}>();

const emit = defineEmits<{
    files: [files: File[]];
}>();

const input = useTemplateRef<HTMLInputElement>('input');
const dragging = ref(false);
let depth = 0;

const hasFiles = (event: DragEvent) =>
    event.dataTransfer?.types.includes('Files') ?? false;

function onEnter(event: DragEvent) {
    if (props.disabled || !hasFiles(event)) {
        return;
    }

    depth++;
    dragging.value = true;
}

function onLeave() {
    depth = Math.max(0, depth - 1);
    dragging.value = depth > 0;
}

function onDrop(event: DragEvent) {
    depth = 0;
    dragging.value = false;

    if (props.disabled || !event.dataTransfer) {
        return;
    }

    const files = [...event.dataTransfer.files];

    if (files.length > 0) {
        emit('files', props.multiple === false ? files.slice(0, 1) : files);
    }
}

function onPick() {
    const files = [...(input.value?.files ?? [])];

    if (files.length > 0) {
        emit('files', files);
    }

    if (input.value) {
        input.value.value = '';
    }
}

function choose() {
    input.value?.click();
}

defineExpose({ choose });
</script>

<template>
    <div
        class="relative"
        @dragenter.prevent="onEnter"
        @dragover.prevent
        @dragleave.prevent="onLeave"
        @drop.prevent="onDrop"
    >
        <slot />

        <input
            ref="input"
            type="file"
            class="hidden"
            :multiple="multiple !== false"
            @change="onPick"
        />

        <div
            v-if="dragging"
            class="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-primary bg-background/90 text-sm font-medium"
        >
            <Upload class="size-6" />
            {{ label ?? 'Drop files to upload them here' }}
        </div>
    </div>
</template>
