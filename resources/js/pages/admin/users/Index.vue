<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Mail, UserPlus } from '@lucide/vue';
import { ref, watch } from 'vue';
import InviteLinkDialog from '@/components/users/InviteLinkDialog.vue';
import type { InviteLink } from '@/components/users/InviteLinkDialog.vue';
import InviteUserDialog from '@/components/users/InviteUserDialog.vue';
import RemoveUserDialog from '@/components/users/RemoveUserDialog.vue';
import TransferOwnershipDialog from '@/components/users/TransferOwnershipDialog.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAbilities } from '@/composables/useAbilities';
import { relativeTime } from '@/lib/time';
import { index, update } from '@/routes/admin/users';
import { destroy as withdraw, resend } from '@/routes/admin/users/invites';

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

type Invite = {
    id: number;
    email: string;
    role: string;
    expiresAt: string;
    expired: boolean;
    manageable: boolean;
};

defineProps<{
    members: Member[];
    roles: RoleOption[];
    invites: Invite[];
    /** The Administrators the install owner may hand ownership to. */
    candidates: { id: number; name: string; email: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

const { can, installOwner } = useAbilities();

const inviteOpen = ref(false);
const ownershipOpen = ref(false);
const linkOpen = ref(false);
const shown = ref<InviteLink | null>(null);

// Sending or resending an invitation flashes its link back, once.
const page = usePage();
watch(
    () => page.flash,
    (flash) => {
        const invite = (flash as { invite?: InviteLink } | undefined)?.invite;

        if (invite) {
            shown.value = invite;
            linkOpen.value = true;
        }
    },
    { immediate: true },
);

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
        >
            <template
                v-if="
                    can('users.invite') ||
                    (installOwner && candidates.length > 0)
                "
                #actions
            >
                <Button
                    v-if="installOwner && candidates.length > 0"
                    variant="ghost"
                    @click="ownershipOpen = true"
                >
                    <KeyRound />
                    Hand over ownership
                </Button>
                <Button v-if="can('users.invite')" @click="inviteOpen = true">
                    <UserPlus />
                    Invite
                </Button>
            </template>
        </PageHeader>

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

        <section v-if="invites.length > 0" class="grid gap-3">
            <h2 class="text-base">Waiting to join</h2>
            <div class="data-table rounded-lg border">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2 max-sm:hidden">Role</th>
                            <th class="px-4 py-2 max-sm:hidden">Link</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="invite in invites"
                            :key="invite.id"
                            class="align-middle"
                            :data-test="`invite-${invite.email}`"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ invite.email }}
                            </td>
                            <td class="px-4 py-2 max-sm:hidden">
                                {{ invite.role }}
                            </td>
                            <td
                                class="px-4 py-2 text-muted-foreground max-sm:hidden"
                            >
                                {{
                                    invite.expired
                                        ? 'Expired'
                                        : `Expires ${relativeTime(invite.expiresAt)}`
                                }}
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <template
                                    v-if="
                                        invite.manageable && can('users.invite')
                                    "
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            router.post(
                                                resend(invite.id).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        "
                                    >
                                        <Mail />
                                        <span class="max-sm:sr-only"
                                            >Send again</span
                                        >
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive"
                                        @click="
                                            router.delete(
                                                withdraw(invite.id).url,
                                                { preserveScroll: true },
                                            )
                                        "
                                    >
                                        <span>Withdraw</span>
                                    </Button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <InviteUserDialog v-model:open="inviteOpen" :roles="roles" />
        <InviteLinkDialog v-model:open="linkOpen" :invite="shown" />
        <TransferOwnershipDialog
            v-model:open="ownershipOpen"
            :candidates="candidates"
        />
    </PageBody>
</template>
