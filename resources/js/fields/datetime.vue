<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asString, booleanSetting } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

/**
 * Dates are stored as `YYYY-MM-DD`. Date-times are stored in UTC as
 * `YYYY-MM-DDTHH:mm:ssZ` and shown in the editor's own time zone.
 */
const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const dateOnly = computed(() => booleanSetting(props.field, 'date_only'));
const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

const pad = (value: number) => String(value).padStart(2, '0');

const inputValue = computed(() => {
    const stored = asString(props.modelValue);

    if (stored === '') {
        return '';
    }

    if (dateOnly.value) {
        return stored.slice(0, 10);
    }

    const date = new Date(stored);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
});

const onInput = (raw: string | number) => {
    const local = String(raw);

    if (local === '') {
        emit('update:modelValue', null);

        return;
    }

    if (dateOnly.value) {
        emit('update:modelValue', local);

        return;
    }

    // A datetime-local value without a zone is parsed as local time.
    const date = new Date(local);

    emit(
        'update:modelValue',
        Number.isNaN(date.getTime())
            ? null
            : date.toISOString().replace(/\.\d{3}Z$/, 'Z'),
    );
};
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <Input
                :id="id"
                :type="dateOnly ? 'date' : 'datetime-local'"
                class="w-auto"
                :model-value="inputValue"
                :required="field.required"
                :disabled="disabled"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="onInput"
            />
            <span v-if="!dateOnly" class="text-xs text-muted-foreground">
                Your time zone: {{ timeZone }}
            </span>
        </div>
    </FieldShell>
</template>
