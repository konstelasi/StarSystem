<script setup lang="ts">
import CodeEditor from '@/fields/editors/CodeEditor.vue';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asString, stringSetting } from '@/fields/support';
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
        <CodeEditor
            :id="id"
            :model-value="asString(modelValue)"
            :language="stringSetting(field, 'language')"
            :disabled="disabled"
            :invalid="invalid"
            :described-by="describedBy"
            @update:model-value="emit('update:modelValue', $event)"
        />
    </FieldShell>
</template>
