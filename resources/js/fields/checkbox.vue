<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import FieldShell from '@/fields/parts/FieldShell.vue';
import type { FieldRendererProps } from '@/fields/support';

defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <FieldShell
        v-slot="{ id, labelId, describedBy, invalid }"
        :field="field"
        :error="error"
        label-mode="none"
    >
        <div class="flex items-center gap-2">
            <Checkbox
                :id="id"
                :model-value="modelValue === true"
                :disabled="disabled"
                :name="field.key"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="emit('update:modelValue', $event === true)"
            />
            <Label :id="labelId" :for="id">
                {{ field.label }}
                <span
                    v-if="field.required"
                    class="text-destructive"
                    aria-hidden="true"
                    >*</span
                >
                <span v-if="field.required" class="sr-only">(required)</span>
            </Label>
        </div>
    </FieldShell>
</template>
