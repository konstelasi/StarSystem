<script setup lang="ts">
import {
    AlignLeft,
    Box,
    Calendar,
    CircleDot,
    Code,
    Columns2,
    EyeOff,
    Hash,
    Link,
    List,
    ListChecks,
    Mail,
    Palette,
    Paperclip,
    Pilcrow,
    RectangleHorizontal,
    SlidersHorizontal,
    SquareCheck,
    Type,
    PanelTop,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * Field types name their icon by lucide name. Bundling every lucide icon
 * would be heavy, so known names map here and anything else gets a box.
 */
const icons: Record<string, Component> = {
    'align-left': AlignLeft,
    calendar: Calendar,
    'circle-dot': CircleDot,
    code: Code,
    'columns-2': Columns2,
    'eye-off': EyeOff,
    hash: Hash,
    link: Link,
    list: List,
    'list-checks': ListChecks,
    mail: Mail,
    palette: Palette,
    'panel-top': PanelTop,
    paperclip: Paperclip,
    pilcrow: Pilcrow,
    'rectangle-horizontal': RectangleHorizontal,
    'sliders-horizontal': SlidersHorizontal,
    'square-check': SquareCheck,
    type: Type,
};

const props = defineProps<{ name: string | undefined }>();

const icon = computed(() => (props.name && icons[props.name]) || Box);
</script>

<template>
    <component :is="icon" aria-hidden="true" />
</template>
