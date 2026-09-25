<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useBuilderContext } from '@/components/builder/context';
import { Textarea } from '@/components/ui/textarea';
import { parseSchemaJson } from '@/lib/modelSchema';

const { builder } = useBuilderContext();

/**
 * The text last produced from `builder.schema`, so the watcher below can
 * tell "the schema changed elsewhere" (build tab, JSON tab applying its own
 * edit) apart from "this editor is mid-edit" and only overwrite the textarea
 * in the first case. This is what keeps the two directions from fighting.
 */
let lastApplied = '';

const text = ref(stringify(builder.schema.value));
lastApplied = text.value;

const error = computed(() => {
    const result = parseSchemaJson(text.value);

    return result.ok ? null : result.error;
});

function stringify(schema: unknown): string {
    return JSON.stringify(schema, null, 2);
}

watch(
    builder.schema,
    (schema) => {
        const next = stringify(schema);

        if (next !== lastApplied) {
            lastApplied = next;
            text.value = next;
        }
    },
    { deep: true },
);

watch(text, (value) => {
    const result = parseSchemaJson(value);

    if (result.ok) {
        lastApplied = value;
        builder.replaceSchema(result.value);
    }
});
</script>

<template>
    <div class="grid gap-3">
        <p class="text-sm text-muted-foreground">
            The schema as JSON. Edits here apply to the builder as you type,
            once they parse.
        </p>
        <div
            v-if="error"
            class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ error }}</span>
        </div>
        <Textarea
            v-model="text"
            spellcheck="false"
            autocapitalize="off"
            class="field-sizing-fixed min-h-[28rem] font-mono text-xs"
            aria-label="Schema JSON"
            :aria-invalid="error ? true : undefined"
        />
    </div>
</template>
