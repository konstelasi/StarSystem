<script setup lang="ts">
import { asString } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

// Editors never see a hidden field; it only carries its value along.
defineProps<FieldRendererProps>();
defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <input
        type="hidden"
        :id="`field-${field.uuid}`"
        :name="field.key"
        :value="asString(modelValue)"
        :disabled="disabled"
    />
</template>
