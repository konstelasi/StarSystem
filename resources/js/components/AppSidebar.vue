<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    Blocks,
    Download,
    Images,
    LayoutGrid,
    Puzzle,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { files, health, updates } from '@/routes/admin';
import { index as models } from '@/routes/admin/models';
import { index as modules } from '@/routes/admin/modules';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Media',
        href: files(),
        icon: Images,
    },
    {
        title: 'Health',
        href: health(),
        icon: Activity,
    },
    {
        title: 'Models',
        href: models(),
        icon: Blocks,
    },
    {
        title: 'Modules',
        href: modules(),
        icon: Puzzle,
    },
    {
        title: 'Updates',
        href: updates(),
        icon: Download,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
