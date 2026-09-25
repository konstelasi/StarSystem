<script setup lang="ts">
import { Textarea } from '@/components/ui/textarea';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asString, numberSetting } from '@/fields/support';
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
        <Textarea
            :id="id"
            class="field-sizing-fixed min-h-0"
            :rows="numberSetting(field, 'rows') ?? 4"
            :model-value="asString(modelValue)"
            :required="field.required"
            :disabled="disabled"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @update:model-value="emit('update:modelValue', String($event))"
        />
    </FieldShell>
</template>
