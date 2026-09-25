<script setup lang="ts">
import RichTextEditor from '@/fields/editors/RichTextEditor.vue';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asString } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <RichTextEditor
            :id="id"
            :model-value="asString(modelValue)"
            :disabled="disabled"
            :invalid="invalid"
            :described-by="describedBy"
            @update:model-value="emit('update:modelValue', $event)"
        />
    </FieldShell>
</template>
