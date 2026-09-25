<script setup lang="ts">
import { Puzzle } from '@lucide/vue';
import FieldShell from '@/fields/parts/FieldShell.vue';
import type { FieldRendererProps } from '@/fields/support';

// Fallback for types without a renderer, e.g. a module that isn't loaded.
defineProps<FieldRendererProps>();
defineEmits<{ 'update:modelValue': [value: unknown] }>();
</script>

<template>
    <FieldShell v-slot="{ id }" :field="field" :error="error">
        <div
            :id="id"
            class="flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
        >
            <Puzzle class="size-4 shrink-0" aria-hidden="true" />
            <p>
                “{{ field.type }}” fields can't be shown here. The module that
                provides them may be turned off.
            </p>
        </div>
    </FieldShell>
</template>
