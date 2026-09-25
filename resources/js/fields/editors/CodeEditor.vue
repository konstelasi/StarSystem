<script setup lang="ts">
/**
 * Editor seam for code. For now this is a monospace textarea. A real code
 * editor replaces this file only: keep the props and the emitted string the
 * same, and every code field picks it up.
 */
defineProps<{
    id: string;
    modelValue: string;
    language?: string | null;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        class="overflow-hidden rounded-md border border-input shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 has-[[aria-invalid=true]]:border-destructive dark:bg-input/30"
    >
        <div
            class="flex items-center justify-between border-b bg-muted/50 px-3 py-1.5 font-mono text-xs text-muted-foreground"
            aria-hidden="true"
        >
            <span>{{ language || 'Plain text' }}</span>
        </div>
        <textarea
            :id="id"
            class="block min-h-40 w-full resize-y bg-transparent px-3 py-2 font-mono text-sm leading-relaxed outline-none disabled:cursor-not-allowed disabled:opacity-50"
            wrap="off"
            spellcheck="false"
            autocapitalize="off"
            autocomplete="off"
            :value="modelValue"
            :disabled="disabled"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLTextAreaElement).value,
                )
            "
        />
    </div>
</template>
