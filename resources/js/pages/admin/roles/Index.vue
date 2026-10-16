<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Copy, Eye, Pencil, PlusCircle } from '@lucide/vue';
import DeleteRoleDialog from '@/components/roles/DeleteRoleDialog.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAbilities } from '@/composables/useAbilities';
import { create, duplicate, edit, index } from '@/routes/admin/roles';

type RoleRow = {
    id: number;
    name: string;
    system: boolean;
    people: number;
    abilities: number;
    editable: boolean;
    duplicable: boolean;
    mine: boolean;
};

defineProps<{
    roles: RoleRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles', href: index() }],
    },
});

const { can } = useAbilities();
</script>

<template>
    <Head title="Roles" />

    <PageBody>
        <PageHeader
            title="Roles"
            description="What each role lets someone do on this site. The built-in roles are fixed. Copy one to make your own."
        >
            <template v-if="can('roles.create')" #actions>
                <Button as-child>
                    <Link :href="create()">
                        <PlusCircle />
                        New role
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div class="data-table rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-2">Role</th>
                        <th class="px-4 py-2 max-sm:hidden">People</th>
                        <th class="px-4 py-2 max-sm:hidden">Abilities</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="role in roles"
                        :key="role.id"
                        class="align-middle"
                        :data-test="`role-${role.name}`"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ role.name }}
                            <Badge v-if="role.system" variant="outline">
                                Built in
                            </Badge>
                            <Badge v-if="role.mine" variant="secondary">
                                Yours
                            </Badge>
                        </td>
                        <td class="px-4 py-2 tabular-nums max-sm:hidden">
                            {{ role.people }}
                        </td>
                        <td class="px-4 py-2 tabular-nums max-sm:hidden">
                            {{ role.abilities }}
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <Button as-child variant="ghost" size="sm">
                                <Link :href="edit(role.id)">
                                    <Pencil
                                        v-if="
                                            role.editable && can('roles.update')
                                        "
                                    />
                                    <Eye v-else />
                                    <span class="max-sm:sr-only">{{
                                        role.editable && can('roles.update')
                                            ? 'Edit'
                                            : 'View'
                                    }}</span>
                                </Link>
                            </Button>
                            <Button
                                v-if="role.duplicable && can('roles.create')"
                                variant="ghost"
                                size="sm"
                                @click="router.post(duplicate(role.id).url)"
                            >
                                <Copy />
                                <span class="max-sm:sr-only">Copy</span>
                            </Button>
                            <DeleteRoleDialog
                                v-if="
                                    role.editable &&
                                    role.people === 0 &&
                                    can('roles.delete')
                                "
                                :role="{ id: role.id, name: role.name }"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PageBody>
</template>
