<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import FieldShell from '@/fields/parts/FieldShell.vue';
import RuntimeOptionsNotice from '@/fields/parts/RuntimeOptionsNotice.vue';
import {
    asString,
    optionsSourceSetting,
    staticOptions,
} from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const source = computed(() => optionsSourceSetting(props.field));
const options = computed(() => staticOptions(source.value));
</script>

<template>
    <FieldShell
        v-slot="{ id, labelId, describedBy, invalid }"
        :field="field"
        :error="error"
        label-mode="group"
    >
        <template v-if="source.kind !== 'static'">
            <RuntimeOptionsNotice
                :source="source"
                :described-by="describedBy"
            />
        </template>
        <p
            v-else-if="options?.length === 0"
            class="text-sm text-muted-foreground"
        >
            No choices yet. Add some in the field settings.
        </p>
        <RadioGroup
            v-else
            :model-value="asString(modelValue) || undefined"
            :disabled="disabled"
            :required="field.required"
            :name="field.key"
            :aria-labelledby="labelId"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            @update:model-value="
                emit(
                    'update:modelValue',
                    $event == null ? null : String($event),
                )
            "
        >
            <div
                v-for="(option, index) in options"
                :key="option.value"
                class="flex items-center gap-2"
            >
                <RadioGroupItem :id="`${id}-${index}`" :value="option.value" />
                <Label :for="`${id}-${index}`" class="font-normal">
                    {{ option.label }}
                </Label>
            </div>
        </RadioGroup>
    </FieldShell>
</template>
