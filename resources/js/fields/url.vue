<script setup lang="ts">
import { Input } from '@/components/ui/input';
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
        <Input
            :id="id"
            type="url"
            inputmode="url"
            :model-value="asString(modelValue)"
            :placeholder="
                stringSetting(field, 'placeholder') ?? 'https://example.com'
            "
            :required="field.required"
            :disabled="disabled"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @update:model-value="emit('update:modelValue', String($event))"
        />
    </FieldShell>
</template>
