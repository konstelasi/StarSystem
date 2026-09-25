<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asString, numberSetting, stringSetting } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const value = computed(() => asString(props.modelValue));
const maxLength = computed(() => numberSetting(props.field, 'max_length'));
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <Input
            :id="id"
            type="text"
            :model-value="value"
            :placeholder="stringSetting(field, 'placeholder') ?? undefined"
            :maxlength="maxLength ?? undefined"
            :required="field.required"
            :disabled="disabled"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @update:model-value="emit('update:modelValue', String($event))"
        />
        <p
            v-if="maxLength !== null"
            class="-mt-1 text-right text-xs text-muted-foreground tabular-nums"
            aria-live="polite"
        >
            {{ value.length }} / {{ maxLength }}
        </p>
    </FieldShell>
</template>
