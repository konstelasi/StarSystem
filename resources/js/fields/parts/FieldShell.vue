<script setup lang="ts">
import { Label } from '@/components/ui/label';
import { useFieldIds } from '@/fields/support';
import type { FieldSpecJson } from '@/types/schema';

const props = withDefaults(
    defineProps<{
        field: FieldSpecJson;
        error?: string;
        /**
         * `control`: a label bound to one input. `group`: a caption for a set
         * of inputs (radio buttons, checkboxes). `none`: the renderer places
         * the label itself, e.g. beside a single checkbox.
         */
        labelMode?: 'control' | 'group' | 'none';
    }>(),
    { labelMode: 'control' },
);

const { id, labelId, helperId, errorId, describedBy } = useFieldIds(props);
</script>

<template>
    <div
        class="grid gap-2"
        :role="labelMode === 'group' ? 'group' : undefined"
        :aria-labelledby="labelMode === 'group' ? labelId : undefined"
        :data-field-type="field.type"
    >
        <Label v-if="labelMode === 'control'" :id="labelId" :for="id">
            {{ field.label }}
            <span
                v-if="field.required"
                class="text-destructive"
                aria-hidden="true"
                >*</span
            >
            <span v-if="field.required" class="sr-only">(required)</span>
        </Label>
        <span
            v-else-if="labelMode === 'group'"
            :id="labelId"
            class="text-sm leading-none font-medium"
        >
            {{ field.label }}
            <span
                v-if="field.required"
                class="text-destructive"
                aria-hidden="true"
                >*</span
            >
            <span v-if="field.required" class="sr-only">(required)</span>
        </span>

        <slot
            :id="id"
            :label-id="labelId"
            :described-by="describedBy"
            :invalid="error ? true : undefined"
        />

        <p
            v-if="field.helper"
            :id="helperId"
            class="text-sm text-muted-foreground"
        >
            {{ field.helper }}
        </p>
        <p v-if="error" :id="errorId" class="text-sm text-destructive">
            {{ error }}
        </p>
    </div>
</template>
