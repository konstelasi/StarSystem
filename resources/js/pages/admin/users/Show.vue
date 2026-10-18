<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ArrowLeft, KeyRound, LogOut, Pause, Play } from '@lucide/vue';
import { ref, watch } from 'vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import RemoveUserDialog from '@/components/users/RemoveUserDialog.vue';
import ResetLinkDialog from '@/components/users/ResetLinkDialog.vue';
import type { ResetLink } from '@/components/users/ResetLinkDialog.vue';
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
import { activity as activityPage } from '@/routes/admin';
import {
    index,
    resetLink,
    resume,
    show,
    suspend,
    update,
} from '@/routes/admin/users';
import { destroy as signOut } from '@/routes/admin/users/sessions';

type Member = {
    id: number;
    userId: number;
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
    invitedBy: string | null;
    /** Whether they belong to another site too. */
    elsewhere: boolean;
};

type Row = {
    id: number;
    at: string;
    actor: string | null;
    label: string;
    subject: string | null;
    details: string[];
};

const props = defineProps<{
    member: Member;
    /** Devices signed in. Null where sessions aren't kept in the database. */
    devices: number | null;
    /** Null for someone who may not read the log. */
    activity: Row[] | null;
    mailConfigured: boolean;
    roles: { id: number; name: string; assignable: boolean }[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Users', href: index() },
        { title: props.member.name, href: show(props.member.id) },
    ],
});

const { can } = useAbilities();

const busy = ref(false);
const linkOpen = ref(false);
const shown = ref<ResetLink | null>(null);

// A reset link that couldn't be emailed comes back once, to be passed on.
const page = usePage();
watch(
    () => page.flash,
    (flash) => {
        const reset = (flash as { reset?: ResetLink } | undefined)?.reset;

        if (reset) {
            shown.value = reset;
            linkOpen.value = true;
        }
    },
    { immediate: true },
);

function act(send: (options: object) => void) {
    send({
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

function changeRole(roleId: unknown) {
    if (roleId === String(props.member.role.id)) {
        return;
    }

    router.put(
        update(props.member.id).url,
        { role_id: Number(roleId) },
        { preserveScroll: true },
    );
}

const when = (iso: string | null, never: string) =>
    iso ? relativeTime(iso) : never;

const full = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString() : undefined;

/** "Rina changed someone's role", or just the label when there is no one. */
const sentence = (row: Row) =>
    row.actor
        ? `${row.actor} ${row.label.charAt(0).toLowerCase()}${row.label.slice(1)}`
        : row.label;
</script>

<template>
    <Head :title="member.name" />

    <PageBody>
        <PageHeader :title="member.name" :description="member.email">
            <template #badge>
                <Badge v-if="member.isYou" variant="secondary">You</Badge>
                <Badge v-if="member.isOwner" variant="outline">Owner</Badge>
                <Badge
                    v-if="member.suspended"
                    variant="outline"
                    class="text-destructive"
                >
                    Paused
                </Badge>
            </template>
            <template #actions>
                <Button variant="ghost" as-child>
                    <Link :href="index()">
                        <ArrowLeft />
                        All users
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_19rem]">
            <div class="grid gap-8">
                <dl
                    class="divide-y rounded-lg border text-sm"
                    data-test="member-details"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Role</dt>
                        <dd>
                            <Select
                                v-if="
                                    member.editable &&
                                    can('users.update') &&
                                    !member.isOwner
                                "
                                :model-value="String(member.role.id)"
                                @update:model-value="changeRole"
                            >
                                <SelectTrigger
                                    class="w-44"
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
                            <span v-else class="font-medium">
                                {{ member.role.name }}
                            </span>
                        </dd>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Joined</dt>
                        <dd :title="full(member.joinedAt)">
                            {{ when(member.joinedAt, 'Unknown') }}
                        </dd>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Last seen</dt>
                        <dd :title="full(member.lastSeenAt)">
                            {{ when(member.lastSeenAt, 'Never') }}
                        </dd>
                    </div>
                    <div
                        v-if="member.invitedBy"
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Invited by</dt>
                        <dd>{{ member.invitedBy }}</dd>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">
                            Two-factor sign-in
                        </dt>
                        <dd>{{ member.twoFactor ? 'On' : 'Off' }}</dd>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                    >
                        <dt class="text-muted-foreground">Signed in on</dt>
                        <dd data-test="member-devices">
                            {{
                                devices === null
                                    ? 'Not tracked on this install'
                                    : devices === 1
                                      ? '1 device'
                                      : `${devices} devices`
                            }}
                        </dd>
                    </div>
                </dl>

                <section v-if="activity" class="grid gap-3">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h2 class="text-base">Recent activity</h2>
                        <Link
                            :href="
                                activityPage({
                                    query: { person: member.userId },
                                })
                            "
                            class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            See all of it
                        </Link>
                    </div>

                    <p
                        v-if="activity.length === 0"
                        class="rounded-lg border px-4 py-3 text-sm text-muted-foreground"
                    >
                        Nothing has been recorded about them yet.
                    </p>
                    <ol v-else class="divide-y rounded-lg border">
                        <li
                            v-for="row in activity"
                            :key="row.id"
                            class="grid grid-cols-[5.5rem_1fr] gap-x-3 px-4 py-2.5 text-sm"
                        >
                            <time
                                class="text-muted-foreground"
                                :datetime="row.at"
                                :title="full(row.at)"
                            >
                                {{ relativeTime(row.at) }}
                            </time>
                            <div class="min-w-0">
                                <p>{{ sentence(row) }}</p>
                                <p
                                    v-if="row.subject || row.details.length"
                                    class="break-words text-muted-foreground"
                                >
                                    <span v-if="row.subject">{{
                                        row.subject
                                    }}</span>
                                    {{ row.details.join(', ') }}
                                </p>
                            </div>
                        </li>
                    </ol>
                </section>
            </div>

            <aside class="grid gap-4" data-test="member-actions">
                <template v-if="member.editable">
                    <div
                        v-if="can('users.suspend')"
                        class="grid gap-2 rounded-lg border p-4"
                    >
                        <Button
                            v-if="!member.suspended"
                            variant="secondary"
                            :disabled="busy"
                            @click="
                                act((options) =>
                                    router.post(
                                        suspend(member.id).url,
                                        {},
                                        options,
                                    ),
                                )
                            "
                        >
                            <Pause />
                            Pause access
                        </Button>
                        <Button
                            v-else
                            :disabled="busy"
                            @click="
                                act((options) =>
                                    router.delete(
                                        resume(member.id).url,
                                        options,
                                    ),
                                )
                            "
                        >
                            <Play />
                            Resume access
                        </Button>
                        <p class="text-sm text-muted-foreground">
                            {{
                                member.suspended
                                    ? 'They can sign in and use the site again, with the same role.'
                                    : "They're signed out here on their next click and can't sign in until you resume them. Their role and account stay."
                            }}
                        </p>
                    </div>

                    <div
                        v-if="can('users.sign_out')"
                        class="grid gap-2 rounded-lg border p-4"
                    >
                        <Button
                            variant="secondary"
                            :disabled="
                                busy || devices === 0 || member.elsewhere
                            "
                            @click="
                                act((options) =>
                                    router.delete(
                                        signOut(member.id).url,
                                        options,
                                    ),
                                )
                            "
                        >
                            <LogOut />
                            Sign out of every device
                        </Button>
                        <p class="text-sm text-muted-foreground">
                            {{
                                member.elsewhere
                                    ? "They belong to another site too, and a session isn't tied to one, so this would sign them out there as well. Pause them to lock them out of this site."
                                    : 'Ends all their sessions. They can sign in again unless they are paused.'
                            }}
                        </p>
                    </div>

                    <div
                        v-if="can('users.reset_password')"
                        class="grid gap-2 rounded-lg border p-4"
                    >
                        <Button
                            variant="secondary"
                            :disabled="
                                busy || (member.elsewhere && !mailConfigured)
                            "
                            @click="
                                act((options) =>
                                    router.post(
                                        resetLink(member.id).url,
                                        {},
                                        options,
                                    ),
                                )
                            "
                        >
                            <KeyRound />
                            Send a password reset link
                        </Button>
                        <p class="text-sm text-muted-foreground">
                            {{
                                mailConfigured
                                    ? 'We email them a link to choose a new password.'
                                    : member.elsewhere
                                      ? "Email isn't set up here, and they belong to another site too, so only they can reset their password. They can use Forgot password on the sign-in page."
                                      : "Email isn't set up here, so you get a link to pass on yourself."
                            }}
                        </p>
                    </div>

                    <div
                        v-if="can('users.remove')"
                        class="grid gap-2 rounded-lg border border-destructive/40 p-4"
                    >
                        <RemoveUserDialog
                            :member="{ id: member.id, name: member.name }"
                        />
                    </div>
                </template>

                <p
                    v-else
                    class="rounded-lg border p-4 text-sm text-muted-foreground"
                >
                    <template v-if="member.isYou">
                        This is your own account. Change your name, email and
                        password in your settings.
                    </template>
                    <template v-else-if="member.isOwner">
                        The owner of the installation can't be changed from
                        here.
                    </template>
                    <template v-else>
                        Their role holds abilities you don't, so you can't
                        change them.
                    </template>
                </p>
            </aside>
        </div>

        <ResetLinkDialog v-model:open="linkOpen" :reset="shown" />
    </PageBody>
</template>
