<script setup lang="ts">
import { FileIcon, FolderOpen, X } from '@lucide/vue';
import { computed } from 'vue';
import FilePicker from '@/components/files/FilePicker.vue';
import { Button } from '@/components/ui/button';
import FieldShell from '@/fields/parts/FieldShell.vue';
import {
    asStringList,
    booleanSetting,
    stringListSetting,
} from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

/**
 * Stores file uuids: a list when `multiple` is on, otherwise one uuid or
 * null. `accept` (extensions or MIME types) is shown as a hint, but isn't
 * passed to <FilePicker>'s own `accept`, which filters by a coarser kind
 * (image, video, …) rather than an exact pattern; the server still checks
 * the picked uuids exist, regardless of what the picker showed.
 */
const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{
    'update:modelValue': [value: string[] | string | null];
}>();

const multiple = computed(() => booleanSetting(props.field, 'multiple'));
const accept = computed(() => stringListSetting(props.field, 'accept'));
const files = computed(() => asStringList(props.modelValue));

const remove = (id: string) => {
    const rest = files.value.filter((item) => item !== id);

    emit('update:modelValue', multiple.value ? rest : null);
};

const picked = (uuids: string[]) => {
    emit('update:modelValue', multiple.value ? uuids : (uuids[0] ?? null));
};
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <div
            class="rounded-md border border-dashed p-4"
            :class="{ 'border-destructive': invalid }"
        >
            <ul v-if="files.length > 0" class="mb-3 grid gap-2">
                <li
                    v-for="file in files"
                    :key="file"
                    class="flex items-center gap-2 rounded-md bg-muted/60 px-2 py-1.5 text-sm"
                >
                    <FileIcon class="size-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate font-mono text-xs">{{
                        file
                    }}</span>
                    <Button
                        v-if="!disabled"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        :aria-label="`Remove ${file}`"
                        @click="remove(file)"
                    >
                        <X />
                    </Button>
                </li>
            </ul>

            <div class="flex flex-wrap items-center gap-3">
                <FilePicker
                    :multiple="multiple"
                    :selected="files"
                    @select="(uuids) => picked(uuids)"
                >
                    <template #trigger>
                        <Button
                            :id="id"
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="disabled"
                            :aria-describedby="describedBy"
                        >
                            <FolderOpen />
                            {{ multiple ? 'Choose files' : 'Choose a file' }}
                        </Button>
                    </template>
                </FilePicker>
                <span
                    v-if="accept.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    Allowed: {{ accept.join(', ') }}
                </span>
            </div>
        </div>
    </FieldShell>
</template>
