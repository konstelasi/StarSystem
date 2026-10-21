<script setup lang="ts">
import { computed } from 'vue';
import { useBuilderContext } from '@/components/builder/context';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    defaultLabelField,
    labelFieldChoices,
    toModelLink,
} from '@/lib/modelSchema';
import type { BuilderModel, ModelLink } from '@/types/schema';

/**
 * Edits a relation field's `link` setting: which model it links to, and which
 * field of that model names its entries. The fields on offer follow the
 * chosen model. When that is this model they are the live ones, so a field
 * added a moment ago is already a choice.
 */
const props = defineProps<{ id: string; modelValue: unknown }>();
const emit = defineEmits<{ 'update:modelValue': [value: ModelLink] }>();

const { builder, models, modelId } = useBuilderContext();

const link = computed(() => toModelLink(props.modelValue));

// This model first, then the rest in the order the server sent them.
const choices = computed<{ id: number; label: string }[]>(() => [
    {
        id: modelId,
        label: 'This model',
    },
    ...models
        .filter((model) => model.id !== modelId)
        .map((model) => ({ id: model.id, label: model.label })),
]);

// A link to a model that isn't on offer, such as one this person can't view,
// stays visible instead of leaving the select blank.
const unavailable = computed(
    () =>
        link.value.model !== null &&
        !choices.value.some((choice) => choice.id === link.value.model),
);

function targetOf(id: number | null): BuilderModel | undefined {
    if (id === null) {
        return undefined;
    }

    if (id === modelId) {
        return {
            id,
            label: builder.schema.value.model.label,
            fields: builder.schema.value.fields,
        };
    }

    return models.find((model) => model.id === id);
}

const target = computed(() => targetOf(link.value.model));

const fieldChoices = computed(() =>
    labelFieldChoices(target.value?.fields ?? []),
);

function chooseModel(value: unknown) {
    const id = Number(value);

    if (!Number.isInteger(id) || id === link.value.model) {
        return;
    }

    emit('update:modelValue', {
        model: id,
        label_field: defaultLabelField(targetOf(id)?.fields ?? []),
    });
}

function chooseField(value: unknown) {
    emit('update:modelValue', {
        model: link.value.model,
        label_field: typeof value === 'string' && value !== '' ? value : null,
    });
}
</script>

<template>
    <div class="grid gap-3">
        <Select
            :model-value="link.model === null ? undefined : String(link.model)"
            @update:model-value="chooseModel"
        >
            <SelectTrigger :id="id" class="w-full">
                <SelectValue placeholder="Choose a model…" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="choice in choices"
                    :key="choice.id"
                    :value="String(choice.id)"
                >
                    {{ choice.label }}
                </SelectItem>
                <SelectItem
                    v-if="unavailable"
                    :value="String(link.model)"
                    disabled
                >
                    A model that isn't available
                </SelectItem>
            </SelectContent>
        </Select>

        <div v-if="target !== undefined" class="grid gap-1.5">
            <Label :for="`${id}-label-field`"
                >Field that names its entries</Label
            >

            <Select
                v-if="fieldChoices.length > 0"
                :model-value="link.label_field ?? undefined"
                @update:model-value="chooseField"
            >
                <SelectTrigger :id="`${id}-label-field`" class="w-full">
                    <SelectValue placeholder="Choose a field…" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="field in fieldChoices"
                        :key="field.uuid"
                        :value="field.uuid"
                    >
                        {{ field.label || field.key }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <p
                v-else
                :id="`${id}-label-field`"
                tabindex="-1"
                class="text-sm text-muted-foreground"
            >
                {{ target.label }} has no field that can name its entries. Add a
                text field to it first.
            </p>
        </div>

        <p class="text-xs text-muted-foreground">
            The model and "Allow several entries" can't change once this model
            has entries.
        </p>
    </div>
</template>
