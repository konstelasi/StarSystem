<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<!--
  The mark in the text colour with the panel name beside it, as the blog
  admin shows it. The mark is decorative; the name is what screen readers
  read.
-->
<template>
    <AppLogoIcon class="size-8 shrink-0 text-sidebar-foreground" />
    <div class="ml-1 grid flex-1 text-left">
        <span
            class="truncate text-[1.0625rem] leading-tight font-semibold tracking-[-0.01em]"
            >{{ name }}</span
        >
    </div>
</template>
