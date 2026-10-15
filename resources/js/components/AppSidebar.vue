<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Blocks,
    Download,
    FileText,
    Images,
    LayoutGrid,
    Puzzle,
} from '@lucide/vue';
import { computed } from 'vue';
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
import { index as entries } from '@/routes/admin/models/entries';
import { index as modules } from '@/routes/admin/modules';
import type { NavGroup, NavItem } from '@/types';

type NavModel = { id: number; label: string; group: string | null };

const page = usePage();

/** A model's entries pages (list, trash, new, edit) all keep it active. */
const modelItem = (model: NavModel): NavItem => ({
    title: model.label,
    href: entries(model.id),
    icon: FileText,
    matchPrefix: true,
});

/** The models list and the builder, but not a model's entries. */
const isModelsPage = (path: string) =>
    path.startsWith(models().url) && !/\/entries(\/|$)/.test(path);

const groups = computed<NavGroup[]>(() => {
    const navModels = (page.props.navModels as NavModel[] | undefined) ?? [];
    const named = new Map<string, NavItem[]>();

    for (const model of navModels) {
        if (model.group !== null) {
            named.set(model.group, [
                ...(named.get(model.group) ?? []),
                modelItem(model),
            ]);
        }
    }

    return [
        {
            items: [
                { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            ],
        },
        {
            label: 'Content',
            items: [
                ...navModels
                    .filter((model) => model.group === null)
                    .map(modelItem),
                { title: 'Media', href: files(), icon: Images },
            ],
        },
        ...[...named]
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([label, items]) => ({ label, items })),
        {
            label: 'Build',
            items: [
                {
                    title: 'Models',
                    href: models(),
                    icon: Blocks,
                    activeWhen: isModelsPage,
                },
                { title: 'Modules', href: modules(), icon: Puzzle },
            ],
        },
        {
            label: 'System',
            items: [
                { title: 'Health', href: health(), icon: Activity },
                { title: 'Updates', href: updates(), icon: Download },
            ],
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
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
            <NavMain :groups="groups" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
