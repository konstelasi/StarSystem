<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import FieldShell from '@/fields/parts/FieldShell.vue';
import { asString } from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const value = computed(() => asString(props.modelValue));

// The native picker only understands #rrggbb, so fall back for anything else.
const swatch = computed(() =>
    /^#[0-9a-f]{6}$/i.test(value.value) ? value.value : '#000000',
);
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
    >
        <div class="flex items-center gap-2">
            <input
                type="color"
                class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-transparent p-1 disabled:cursor-not-allowed disabled:opacity-50"
                :value="swatch"
                :disabled="disabled"
                :aria-label="`${field.label}: pick a colour`"
                @input="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <Input
                :id="id"
                class="font-mono"
                :model-value="value"
                placeholder="#000000"
                maxlength="7"
                spellcheck="false"
                :required="field.required"
                :disabled="disabled"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                @update:model-value="emit('update:modelValue', String($event))"
            />
        </div>
    </FieldShell>
</template>
