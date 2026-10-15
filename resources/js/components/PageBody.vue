<script setup lang="ts">
/**
 * What every admin page sits in, so the pages share one padding and one
 * gap between their parts. It takes the full width, as the pages did
 * before; a page that reads better narrower passes its own `max-w-*`.
 */
</script>

<template>
    <div class="flex flex-1 flex-col gap-4 p-4">
        <slot />
    </div>
</template>
