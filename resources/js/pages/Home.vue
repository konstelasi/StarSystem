<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    siteName: string;
}>();
</script>

<template>
    <Head :title="siteName" />

    <main
        class="min-h-svh bg-background px-6 py-16 text-foreground sm:px-12 sm:py-24"
    >
        <h1 class="text-2xl font-semibold tracking-tight">{{ siteName }}</h1>
        <p class="mt-2 text-muted-foreground">
            Nothing has been published here yet.
        </p>
    </main>
</template>
