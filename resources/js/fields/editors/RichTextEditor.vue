<script setup lang="ts">
/**
 * Editor seam for rich text. For now this is a plain, comfortable textarea.
 * A real editor replaces this file only: keep the props and the emitted
 * string (HTML) the same, and every rich_text field picks it up.
 */
defineProps<{
    id: string;
    modelValue: string;
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
            class="border-b bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
            aria-hidden="true"
        >
            Formatted text
        </div>
        <textarea
            :id="id"
            class="block min-h-40 w-full resize-y bg-transparent px-3 py-2 text-base leading-relaxed outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
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
