<script setup lang="ts">
import { computed } from 'vue';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asNumber, numberSetting } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const min = computed(() => numberSetting(props.field, 'min') ?? 0);
const max = computed(() => numberSetting(props.field, 'max') ?? 100);
const step = computed(() => numberSetting(props.field, 'step') ?? 1);
const value = computed(() => asNumber(props.modelValue));
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <div class="flex items-center gap-3">
            <span class="text-xs text-muted-foreground tabular-nums">{{
                min
            }}</span>
            <input
                :id="id"
                type="range"
                class="h-2 w-full min-w-0 cursor-pointer accent-primary disabled:cursor-not-allowed disabled:opacity-50"
                :min="min"
                :max="max"
                :step="step"
                :value="value ?? min"
                :disabled="disabled"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                :aria-valuetext="value === null ? 'Not set' : String(value)"
                @input="
                    emit(
                        'update:modelValue',
                        asNumber(($event.target as HTMLInputElement).value),
                    )
                "
            />
            <span class="text-xs text-muted-foreground tabular-nums">{{
                max
            }}</span>
            <output
                :for="id"
                class="min-w-12 rounded-md border px-2 py-1 text-center text-sm tabular-nums"
                :class="{ 'text-muted-foreground': value === null }"
            >
                {{ value ?? '–' }}
            </output>
        </div>
    </FieldShell>
</template>
