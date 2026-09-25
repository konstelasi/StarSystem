<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import FieldShell from '@/fields/parts/FieldShell.vue';
import RuntimeOptionsNotice from '@/fields/parts/RuntimeOptionsNotice.vue';
import {
    asStringList,
    optionsSourceSetting,
    staticOptions,
} from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const source = computed(() => optionsSourceSetting(props.field));
const options = computed(() => staticOptions(source.value));
const selected = computed(() => asStringList(props.modelValue));

const toggle = (value: string, checked: boolean) => {
    const rest = selected.value.filter((item) => item !== value);

    // Keep the stored order the same as the options' order.
    const next = checked ? [...rest, value] : rest;
    const order = (options.value ?? []).map((option) => option.value);

    emit(
        'update:modelValue',
        next.sort((a, b) => order.indexOf(a) - order.indexOf(b)),
    );
};
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
        label-mode="group"
    >
        <RuntimeOptionsNotice
            v-if="source.kind !== 'static'"
            :source="source"
            :described-by="describedBy"
        />
        <p
            v-else-if="options?.length === 0"
            class="text-sm text-muted-foreground"
        >
            No choices yet. Add some in the field settings.
        </p>
        <div v-else class="grid gap-2" :aria-describedby="describedBy">
            <div
                v-for="(option, index) in options"
                :key="option.value"
                class="flex items-center gap-2"
            >
                <Checkbox
                    :id="`${id}-${index}`"
                    :model-value="selected.includes(option.value)"
                    :disabled="disabled"
                    :aria-invalid="invalid"
                    @update:model-value="toggle(option.value, $event === true)"
                />
                <Label :for="`${id}-${index}`" class="font-normal">
                    {{ option.label }}
                </Label>
            </div>
        </div>
    </FieldShell>
</template>
