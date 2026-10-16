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
    ScrollText,
    ShieldCheck,
    Users,
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
import { useAbilities } from '@/composables/useAbilities';
import { dashboard } from '@/routes';
import { activity, files, health, updates } from '@/routes/admin';
import { index as models } from '@/routes/admin/models';
import { index as entries } from '@/routes/admin/models/entries';
import { index as modules } from '@/routes/admin/modules';
import { index as roles } from '@/routes/admin/roles';
import { index as users } from '@/routes/admin/users';
import type { NavGroup, NavItem } from '@/types';

type NavModel = { id: number; label: string; group: string | null };

const page = usePage();
const { can, installOwner } = useAbilities();

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
    const navModels = can('entries.view')
        ? ((page.props.navModels as NavModel[] | undefined) ?? [])
        : [];
    const named = new Map<string, NavItem[]>();

    for (const model of navModels) {
        if (model.group !== null) {
            named.set(model.group, [
                ...(named.get(model.group) ?? []),
                modelItem(model),
            ]);
        }
    }

    const all: NavGroup[] = [
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
                {
                    title: 'Media',
                    href: files(),
                    icon: Images,
                    ability: 'media.view',
                },
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
                    ability: 'models.view',
                },
                {
                    title: 'Modules',
                    href: modules(),
                    icon: Puzzle,
                    ownerOnly: true,
                },
            ],
        },
        {
            label: 'System',
            items: [
                {
                    title: 'Health',
                    href: health(),
                    icon: Activity,
                    ability: 'health.view',
                },
                {
                    title: 'Activity',
                    href: activity(),
                    icon: ScrollText,
                    ability: 'activity.view',
                },
                {
                    title: 'Users',
                    href: users(),
                    icon: Users,
                    ability: 'users.view',
                },
                {
                    title: 'Roles',
                    href: roles(),
                    icon: ShieldCheck,
                    ability: 'roles.view',
                    matchPrefix: true,
                },
                {
                    title: 'Updates',
                    href: updates(),
                    icon: Download,
                    ownerOnly: true,
                },
            ],
        },
    ];

    // What the account can't use stays out, and a group left empty goes
    // with it.
    return all
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    (item.ability === undefined || can(item.ability)) &&
                    (!item.ownerOnly || installOwner.value),
            ),
        }))
        .filter((group) => group.items.length > 0);
});
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader class="h-14 shrink-0 justify-center py-0">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="group-data-[collapsible=icon]:justify-center"
                    >
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
