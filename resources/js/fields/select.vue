<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import FieldShell from '@/fields/parts/FieldShell.vue';
import RuntimeOptionsNotice from '@/fields/parts/RuntimeOptionsNotice.vue';
import {
    asString,
    asStringList,
    booleanSetting,
    optionsSourceSetting,
    staticOptions,
} from '@/fields/support';
import type { FieldRendererProps } from '@/fields/support';

const props = defineProps<FieldRendererProps>();
const emit = defineEmits<{
    'update:modelValue': [value: string | string[] | null];
}>();

const multiple = computed(() => booleanSetting(props.field, 'multiple'));
const source = computed(() => optionsSourceSetting(props.field));
const options = computed(() => staticOptions(source.value));

const value = computed(() =>
    multiple.value
        ? asStringList(props.modelValue)
        : asString(props.modelValue) || undefined,
);

const hasValue = computed(() =>
    Array.isArray(value.value) ? value.value.length > 0 : !!value.value,
);

const onUpdate = (next: unknown) => {
    if (multiple.value) {
        emit('update:modelValue', asStringList(next));

        return;
    }

    emit('update:modelValue', asString(next) || null);
};

const clear = () => emit('update:modelValue', multiple.value ? [] : null);
</script>

<template>
    <FieldShell
        v-slot="{ id, describedBy, invalid }"
        :field="field"
        :error="error"
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
        <div v-else class="flex items-center gap-2">
            <Select
                :model-value="value"
                :multiple="multiple"
                :disabled="disabled"
                :required="field.required"
                :name="field.key"
                @update:model-value="onUpdate"
            >
                <SelectTrigger
                    :id="id"
                    class="w-full min-w-0"
                    :aria-invalid="invalid"
                    :aria-describedby="describedBy"
                >
                    <SelectValue
                        :placeholder="
                            multiple ? 'Choose one or more…' : 'Choose one…'
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button
                v-if="hasValue && !field.required && !disabled"
                type="button"
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-label="`Clear ${field.label}`"
                @click="clear"
            >
                <X />
            </Button>
        </div>
    </FieldShell>
</template>
