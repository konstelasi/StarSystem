<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavGroup, NavItem } from '@/types';

defineProps<{
    groups: NavGroup[];
}>();

const { currentUrl, isCurrentUrl } = useCurrentUrl();

const isActive = (item: NavItem) =>
    item.activeWhen
        ? item.activeWhen(currentUrl.value)
        : isCurrentUrl(item.href, undefined, item.matchPrefix);
</script>

<template>
    <SidebarGroup
        v-for="(group, position) in groups"
        :key="position"
        class="px-2 py-1"
    >
        <SidebarGroupLabel v-if="group.label">{{
            group.label
        }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="(item, index) in group.items" :key="index">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
