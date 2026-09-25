<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asNumber, booleanSetting, numberSetting } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const whole = computed(() => booleanSetting(props.field, 'whole'));
const step = computed(
    () => numberSetting(props.field, 'step') ?? (whole.value ? 1 : 'any'),
);

const onInput = (raw: string | number) =>
    emit('update:modelValue', asNumber(raw));
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <Input
            :id="id"
            type="number"
            :inputmode="whole ? 'numeric' : 'decimal'"
            class="max-w-60 tabular-nums"
            :model-value="asNumber(modelValue) ?? ''"
            :min="numberSetting(field, 'min') ?? undefined"
            :max="numberSetting(field, 'max') ?? undefined"
            :step="step"
            :required="field.required"
            :disabled="disabled"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @update:model-value="onInput"
        />
    </FieldShell>
</template>
