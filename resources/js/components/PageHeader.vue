<script setup lang="ts">
/**
 * The top of an admin page: its title, one line about it, and the buttons
 * that act on the whole page. The buttons wrap under the title on a phone.
 * `badge` sits beside the title, for a state like "Unsaved changes".
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="min-w-0 flex-[1_1_18rem] space-y-1">
            <h1
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xl md:text-2xl"
            >
                {{ title }}
                <slot name="badge" />
            </h1>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
