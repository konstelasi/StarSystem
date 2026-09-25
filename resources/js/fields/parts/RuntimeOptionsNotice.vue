<script setup lang="ts">
import { DatabaseZap } from '@lucide/vue';
import { computed } from 'vue';
import type { OptionsSource } from '@/types/schema';

const props = defineProps<{
    source: Exclude<OptionsSource, { kind: 'static' }>;
    describedBy?: string;
}>();

const message = computed(() => {
    if (props.source.kind === 'model') {
        return props.source.model
            ? `The choices are entries from “${props.source.model}”. They load when someone fills in this form.`
            : 'The choices come from another model. Pick which one in the field settings.';
    }

    return props.source.name
        ? `The choices come from “${props.source.name}”, provided by a module. They load when someone fills in this form.`
        : 'The choices come from a module. They load when someone fills in this form.';
});
</script>

<template>
    <div
        class="flex items-start gap-2 rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground"
        :aria-describedby="describedBy"
    >
        <DatabaseZap class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p>{{ message }}</p>
    </div>
</template>
