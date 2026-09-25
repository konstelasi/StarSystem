<script setup lang="ts">
import { FileIcon, FolderOpen, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import FieldShell from '@/fields/parts/FieldShell.vue';
import {
    asStringList,
    booleanSetting,
    stringListSetting,
} from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

/**
 * Stores file ids: a list when `multiple` is on, otherwise one id or null.
 * Picking is not wired up yet; the media library will plug in here.
 */
const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{
    'update:modelValue': [value: string[] | string | null];
}>();

const multiple = computed(() => booleanSetting(props.field, 'multiple'));
const accept = computed(() => stringListSetting(props.field, 'accept'));
const files = computed(() => asStringList(props.modelValue));
const pickerMissing = ref(false);

const remove = (id: string) => {
    const rest = files.value.filter((item) => item !== id);

    emit('update:modelValue', multiple.value ? rest : null);
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
                <Button
                    :id="id"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="disabled"
                    :aria-describedby="describedBy"
                    @click="pickerMissing = true"
                >
                    <FolderOpen />
                    {{ multiple ? 'Choose files' : 'Choose a file' }}
                </Button>
                <span
                    v-if="accept.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    Allowed: {{ accept.join(', ') }}
                </span>
            </div>

            <p
                v-if="pickerMissing"
                class="mt-3 text-sm text-muted-foreground"
                role="status"
            >
                The media library isn't connected to this form yet, so files
                can't be picked here.
            </p>
        </div>
    </FieldShell>
</template>
