<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * What every admin page sits in, so the pages line up. `wide` is for lists
 * and dashboards, `text` for forms that read best at a narrower width, and
 * `full` for a page that lays out its own columns, like the builder.
 */
withDefaults(
    defineProps<{
        width?: 'wide' | 'text' | 'full';
    }>(),
    { width: 'wide' },
);

const widths = {
    wide: 'max-w-[76rem]',
    text: 'max-w-[52rem]',
    full: '',
};
</script>

<template>
    <div
        :class="
            cn(
                'mx-auto flex w-full flex-1 flex-col gap-6 p-4 md:p-6',
                widths[width],
            )
        "
    >
        <slot />
    </div>
</template>
