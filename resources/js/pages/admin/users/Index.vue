<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Mail, ShieldCheck, UserPlus, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Pager from '@/components/Pager.vue';
import BulkRemoveDialog from '@/components/users/BulkRemoveDialog.vue';
import SortButton from '@/components/SortButton.vue';
import UserFilters from '@/components/users/UserFilters.vue';
import type { UserFilterValues } from '@/components/users/UserFilters.vue';
import InviteLinkDialog from '@/components/users/InviteLinkDialog.vue';
import type { InviteLink } from '@/components/users/InviteLinkDialog.vue';
import InviteUserDialog from '@/components/users/InviteUserDialog.vue';
import RemoveUserDialog from '@/components/users/RemoveUserDialog.vue';
import TransferOwnershipDialog from '@/components/users/TransferOwnershipDialog.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAbilities } from '@/composables/useAbilities';
import { relativeTime } from '@/lib/time';
import { index, show, update } from '@/routes/admin/users';
import { update as bulkUpdate } from '@/routes/admin/users/bulk';
import { destroy as withdraw, resend } from '@/routes/admin/users/invites';

type Member = {
    id: number;
    name: string;
    email: string;
    role: { id: number; name: string };
    isYou: boolean;
    isOwner: boolean;
    twoFactor: boolean;
    lastSeenAt: string | null;
    joinedAt: string | null;
    suspended: boolean;
    editable: boolean;
};

/** The install owner, who uses every site without a membership. */
type Owner = {
    name: string;
    email: string;
    isYou: boolean;
    twoFactor: boolean;
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

const props = defineProps<{
    members: Member[];
    owners: Owner[];
    filters: UserFilterValues;
    pagination: {
        page: number;
        pages: number;
        total: number;
        from: number;
        to: number;
    };
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

const filtered = computed(
    () =>
        props.filters.q !== null ||
        props.filters.role !== null ||
        props.filters.status !== null,
);

/** A new visit with the filters changed, back on the first page. */
function apply(change: Partial<UserFilterValues>) {
    visit({ ...props.filters, ...change }, 1);
}

function goTo(page: number) {
    visit(props.filters, page);
}

function visit(values: UserFilterValues, pageNumber: number) {
    const data: Record<string, string | number> = {};

    if (values.q !== null) {
        data.q = values.q;
    }

    if (values.role !== null) {
        data.role = values.role;
    }

    if (values.status !== null) {
        data.status = values.status;
    }

    if (values.sort !== 'name') {
        data.sort = values.sort;
    }

    if (pageNumber > 1) {
        data.page = pageNumber;
    }

    router.get(index().url, data, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

/** The memberships ticked for a bulk action. */
const selected = ref<number[]>([]);

const selectable = computed(() =>
    props.members.filter((member) => member.editable),
);

const selectionOn = computed(
    () =>
        (can('users.update') || can('users.remove')) &&
        selectable.value.length > 0,
);

const allSelected = computed(
    () =>
        selectable.value.length > 0 &&
        selectable.value.every((member) => selected.value.includes(member.id)),
);

function toggle(id: number, on: boolean) {
    selected.value = on
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((chosen) => chosen !== id);
}

function toggleAll(on: boolean) {
    selected.value = on ? selectable.value.map((member) => member.id) : [];
}

// A new page or filter shows other people, so the ticks start over.
watch(
    () => props.members.map((member) => member.id).join(','),
    () => (selected.value = []),
);

function changeSelectedRole(roleId: unknown) {
    router.put(
        bulkUpdate().url,
        { ids: selected.value, role_id: Number(roleId) },
        { preserveScroll: true, onSuccess: () => (selected.value = []) },
    );
}

function changeRole(member: Member, roleId: unknown) {
    if (roleId === String(member.role.id)) {
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

        <UserFilters :filters="filters" :roles="roles" @change="apply" />

        <div
            v-if="selected.length > 0"
            class="flex flex-wrap items-center gap-3 rounded-lg border bg-muted/40 px-4 py-2"
            data-test="bulk-bar"
        >
            <span class="text-sm font-medium">
                {{ selected.length }} selected
            </span>
            <Select
                v-if="can('users.update')"
                model-value=""
                @update:model-value="changeSelectedRole"
            >
                <SelectTrigger
                    class="w-48"
                    aria-label="Change the role of the selected people"
                >
                    <SelectValue placeholder="Change role to" />
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
            <BulkRemoveDialog
                v-if="can('users.remove')"
                :ids="selected"
                @done="selected = []"
            />
            <Button variant="ghost" @click="selected = []">
                Clear selection
            </Button>
        </div>

        <Card v-if="members.length === 0 && owners.length === 0">
            <CardHeader class="items-center text-center">
                <Users class="size-8 text-muted-foreground" />
                <CardTitle>
                    {{ filtered ? 'No one matches' : 'No one here yet' }}
                </CardTitle>
                <CardDescription>
                    {{
                        filtered
                            ? 'Try another name, or clear the filters.'
                            : 'Invite someone to get started.'
                    }}
                </CardDescription>
                <Button
                    v-if="filtered"
                    variant="secondary"
                    class="mt-2"
                    @click="apply({ q: null, role: null, status: null })"
                >
                    Clear the filters
                </Button>
            </CardHeader>
        </Card>

        <div v-else class="data-table rounded-lg border">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th v-if="selectionOn" class="w-10 px-4 py-2">
                            <Checkbox
                                :model-value="allSelected"
                                aria-label="Select everyone on this page"
                                @update:model-value="
                                    (on) => toggleAll(on === true)
                                "
                            />
                        </th>
                        <th class="px-4 py-2">
                            <SortButton
                                label="Name"
                                column="name"
                                :sort="filters.sort"
                                @sort="apply({ sort: $event })"
                            />
                        </th>
                        <th class="px-4 py-2 max-sm:hidden">Email</th>
                        <th class="px-4 py-2">Role</th>
                        <th class="px-4 py-2 max-md:hidden">
                            <SortButton
                                label="Last seen"
                                column="seen"
                                :sort="filters.sort"
                                @sort="apply({ sort: $event })"
                            />
                        </th>
                        <th class="px-4 py-2 max-lg:hidden">
                            <SortButton
                                label="Joined"
                                column="joined"
                                :sort="filters.sort"
                                @sort="apply({ sort: $event })"
                            />
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="owner in owners"
                        :key="`owner-${owner.email}`"
                        class="align-middle"
                        :data-test="`user-${owner.email}`"
                    >
                        <td v-if="selectionOn" class="px-4 py-2"></td>
                        <td class="px-4 py-2 font-medium">
                            {{ owner.name }}
                            <Badge v-if="owner.isYou" variant="secondary">
                                You
                            </Badge>
                        </td>
                        <td class="px-4 py-2 max-sm:hidden">
                            {{ owner.email }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge variant="outline">Owner</Badge>
                        </td>
                        <td
                            class="px-4 py-2 text-muted-foreground max-md:hidden"
                        >
                            Not tracked
                        </td>
                        <td
                            class="px-4 py-2 text-muted-foreground max-lg:hidden"
                        >
                            Not tracked
                        </td>
                        <td class="px-4 py-2"></td>
                    </tr>
                    <tr
                        v-for="member in members"
                        :key="member.id"
                        class="align-middle"
                        :class="{ 'text-muted-foreground': member.suspended }"
                        :data-test="`user-${member.email}`"
                    >
                        <td v-if="selectionOn" class="px-4 py-2">
                            <Checkbox
                                v-if="member.editable"
                                :model-value="selected.includes(member.id)"
                                :aria-label="`Select ${member.name}`"
                                @update:model-value="
                                    (on) => toggle(member.id, on === true)
                                "
                            />
                        </td>
                        <td class="px-4 py-2 font-medium">
                            <Link
                                :href="show(member.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ member.name }}
                            </Link>
                            <Badge v-if="member.isYou" variant="secondary">
                                You
                            </Badge>
                            <Badge
                                v-if="member.suspended"
                                variant="outline"
                                class="text-destructive"
                            >
                                Paused
                            </Badge>
                            <ShieldCheck
                                v-if="member.twoFactor"
                                class="ml-1 inline size-4 align-text-bottom text-muted-foreground"
                                aria-label="Two-factor sign-in is on"
                            />
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
                                :model-value="String(member.role.id)"
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
                            <span v-else>{{ member.role.name }}</span>
                        </td>
                        <td
                            class="px-4 py-2 text-muted-foreground max-md:hidden"
                            :title="
                                member.lastSeenAt
                                    ? new Date(
                                          member.lastSeenAt,
                                      ).toLocaleString()
                                    : undefined
                            "
                        >
                            {{
                                member.lastSeenAt
                                    ? relativeTime(member.lastSeenAt)
                                    : 'Never'
                            }}
                        </td>
                        <td
                            class="px-4 py-2 text-muted-foreground max-lg:hidden"
                            :title="
                                member.joinedAt
                                    ? new Date(member.joinedAt).toLocaleString()
                                    : undefined
                            "
                        >
                            {{
                                member.joinedAt
                                    ? relativeTime(member.joinedAt)
                                    : 'Unknown'
                            }}
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <RemoveUserDialog
                                v-if="member.editable && can('users.remove')"
                                :member="{ id: member.id, name: member.name }"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pager
            :page="pagination.page"
            :pages="pagination.pages"
            :from="pagination.from"
            :to="pagination.to"
            :total="pagination.total"
            noun="people"
            @go="goTo"
        />

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
