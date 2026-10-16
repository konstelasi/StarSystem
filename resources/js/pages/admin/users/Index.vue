<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import RemoveUserDialog from '@/components/users/RemoveUserDialog.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAbilities } from '@/composables/useAbilities';
import { index, update } from '@/routes/admin/users';

type Member = {
    /** The membership's id. Null for an install owner without a row. */
    id: number | null;
    name: string;
    email: string;
    role: { id: number; name: string } | null;
    isYou: boolean;
    isOwner: boolean;
    editable: boolean;
};

type RoleOption = { id: number; name: string; assignable: boolean };

defineProps<{
    members: Member[];
    roles: RoleOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

const { can } = useAbilities();

function changeRole(member: Member, roleId: unknown) {
    if (member.id === null || roleId === String(member.role?.id)) {
        return;
    }

    router.put(
        update(member.id).url,
        { role_id: Number(roleId) },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Users" />

    <PageBody>
        <PageHeader
            title="Users"
            description="The people who can sign in to this site, and what each role lets them do."
        />

        <div class="data-table rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2 max-sm:hidden">Email</th>
                        <th class="px-4 py-2">Role</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="member in members"
                        :key="member.id ?? `owner-${member.email}`"
                        class="align-middle"
                        :data-test="`user-${member.email}`"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ member.name }}
                            <Badge v-if="member.isYou" variant="secondary">
                                You
                            </Badge>
                        </td>
                        <td class="px-4 py-2 max-sm:hidden">
                            {{ member.email }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge v-if="member.isOwner" variant="outline">
                                Owner
                            </Badge>
                            <Select
                                v-else-if="
                                    member.editable && can('users.update')
                                "
                                :model-value="String(member.role?.id)"
                                @update:model-value="
                                    (value) => changeRole(member, value)
                                "
                            >
                                <SelectTrigger
                                    class="w-40"
                                    :aria-label="`Role of ${member.name}`"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="role in roles"
                                        :key="role.id"
                                        :value="String(role.id)"
                                        :disabled="!role.assignable"
                                    >
                                        {{ role.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <span v-else>{{ member.role?.name }}</span>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <RemoveUserDialog
                                v-if="
                                    member.id !== null &&
                                    member.editable &&
                                    can('users.remove')
                                "
                                :member="{ id: member.id, name: member.name }"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </PageBody>
</template>
