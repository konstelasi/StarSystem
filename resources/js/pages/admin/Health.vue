<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, PauseCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { health } from '@/routes/admin';

type TickRun = {
    id: number;
    trigger: 'cron' | 'url' | 'manual';
    startedAt: string;
    elapsedSeconds: number | null;
    budgetSeconds: number | null;
    rounds: number | null;
    stopReason: string | null;
    error: string | null;
};

const props = defineProps<{
    profile: 'shared' | 'server';
    tick: {
        expected: boolean;
        staleAfterMinutes: number;
        minutesSinceLast: number | null;
        stale: boolean;
        recent: TickRun[];
    };
    paused: { reason: string; since: string | null } | null;
    server: {
        version: string;
        engine: string | null;
        supported: boolean;
        error: string | null;
    };
    stardust: {
        bootstrapped: boolean;
        sync_queue: number;
        oldest_sync_at: string | null;
        dead_letters: number;
        slots: Record<string, number>;
        imports: Record<string, number>;
        exports: Record<string, number>;
    };
    paths: { name: string; path: string; writable: boolean; public: boolean }[];
    tickUrlEnabled: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Health', href: health() }],
    },
});

const cronMissing = computed(() => props.tick.expected && props.tick.stale);

const lastTickText = computed(() => {
    const minutes = props.tick.minutesSinceLast;

    if (minutes === null) {
        return 'Never';
    }

    return minutes === 0 ? 'Less than a minute ago' : `${minutes} min ago`;
});

const pending = (counts: Record<string, number>) =>
    (counts.pending ?? 0) + (counts.processing ?? 0);

const stopReasonLabel: Record<string, string> = {
    idle: 'Finished all work',
    budget_spent: 'Out of time, continues next run',
    shutdown: 'Stopped',
    lock_contended: 'Skipped, another run was active',
    paused: 'Skipped, background work paused',
};

const formatTime = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="Health" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Alert v-if="paused">
            <PauseCircle />
            <AlertTitle>Updates in progress: background work paused</AlertTitle>
            <AlertDescription>
                <p>
                    StarSystem is updating itself<template v-if="paused.since">
                        (since {{ formatTime(paused.since) }})</template
                    >. Indexing, imports and exports wait until the update
                    finishes, then carry on by themselves.
                </p>
            </AlertDescription>
        </Alert>

        <Alert v-if="cronMissing" variant="destructive">
            <AlertTriangle />
            <AlertTitle>Background work isn't running</AlertTitle>
            <AlertDescription>
                <p v-if="tick.minutesSinceLast === null">
                    StarDust has never ticked on this install.
                </p>
                <p v-else>
                    The last tick was {{ tick.minutesSinceLast }} minutes ago.
                </p>
                <p>
                    Until it runs, new filterable fields won't become filterable
                    and imports and exports won't finish. Add a cron job that
                    runs
                    <code>php artisan schedule:run</code> every minute<template
                        v-if="tickUrlEnabled"
                        >, or have a URL fetch service call
                        <code>/_system/tick</code> with your tick key</template
                    >.
                </p>
            </AlertDescription>
        </Alert>

        <Alert v-if="!server.supported" variant="destructive">
            <AlertTriangle />
            <AlertTitle
                >This database server is too old for StarDust</AlertTitle
            >
            <AlertDescription>
                {{ server.error }} Ask your host for MySQL 8.0.13+ or MariaDB
                10.11+.
            </AlertDescription>
        </Alert>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardDescription>Last tick</CardDescription>
                    <CardTitle class="text-2xl">
                        {{ tick.expected ? lastTickText : 'Daemons' }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <template v-if="tick.expected">
                        Expected every minute. A warning appears after
                        {{ tick.staleAfterMinutes }} minutes.
                    </template>
                    <template v-else>
                        The server profile runs StarDust's daemons instead of a
                        tick.
                    </template>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription>Waiting to be indexed</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ stardust.sync_queue }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <template v-if="stardust.oldest_sync_at">
                        Oldest since {{ formatTime(stardust.oldest_sync_at) }}.
                        Shared by all sites.
                    </template>
                    <template v-else>Nothing waiting.</template>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription>Failed and set aside</CardDescription>
                    <CardTitle
                        class="text-2xl tabular-nums"
                        :class="{
                            'text-destructive': stardust.dead_letters > 0,
                        }"
                    >
                        {{ stardust.dead_letters }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    Entries StarDust couldn't index. They stay readable, but
                    filters miss them until they're replayed.
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardDescription>Imports and exports</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ pending(stardust.imports) }} /
                        {{ pending(stardust.exports) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    Imports / exports in progress on this site. They finish
                    during ticks.
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader>
                    <CardTitle>Recent ticks</CardTitle>
                    <CardDescription>
                        One tick serves every site on this install.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="tick.recent.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No ticks yet.
                    </p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead
                                class="text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="py-2 pr-4 font-medium">
                                        Started
                                    </th>
                                    <th class="py-2 pr-4 font-medium">
                                        Trigger
                                    </th>
                                    <th class="py-2 pr-4 font-medium">
                                        Duration
                                    </th>
                                    <th class="py-2 font-medium">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="run in tick.recent"
                                    :key="run.id"
                                    class="border-t align-top"
                                >
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        {{ formatTime(run.startedAt) }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        <Badge variant="outline">
                                            {{ run.trigger }}
                                        </Badge>
                                    </td>
                                    <td
                                        class="py-2 pr-4 whitespace-nowrap tabular-nums"
                                    >
                                        <template
                                            v-if="run.elapsedSeconds !== null"
                                        >
                                            {{ run.elapsedSeconds.toFixed(1) }}s
                                            of {{ run.budgetSeconds }}s
                                        </template>
                                        <template v-else>–</template>
                                    </td>
                                    <td class="py-2">
                                        <span
                                            v-if="run.error"
                                            class="text-destructive"
                                        >
                                            {{ run.error }}
                                        </span>
                                        <span v-else-if="run.stopReason">
                                            {{
                                                stopReasonLabel[
                                                    run.stopReason
                                                ] ?? run.stopReason
                                            }}
                                        </span>
                                        <span v-else>Running…</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Install</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
                        >
                            <dt class="text-muted-foreground">Database</dt>
                            <dd>
                                {{ server.engine ?? 'Unsupported' }}
                                {{ server.version }}
                            </dd>
                            <dt class="text-muted-foreground">Profile</dt>
                            <dd>
                                {{
                                    profile === 'shared'
                                        ? 'Shared hosting (tick)'
                                        : 'Server (daemons)'
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Tick URL</dt>
                            <dd>{{ tickUrlEnabled ? 'On' : 'Off' }}</dd>
                            <dt class="text-muted-foreground">StarDust</dt>
                            <dd>
                                {{
                                    stardust.bootstrapped
                                        ? 'Installed'
                                        : 'Not installed: run stardust:bootstrap'
                                }}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Folders</CardTitle>
                        <CardDescription>
                            Must be writable and never served to the web.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul class="grid gap-3 text-sm">
                            <li
                                v-for="path in paths"
                                :key="path.name"
                                class="flex items-start gap-2"
                            >
                                <CheckCircle2
                                    v-if="path.writable && !path.public"
                                    class="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400"
                                />
                                <AlertTriangle
                                    v-else
                                    class="mt-0.5 size-4 shrink-0 text-destructive"
                                />
                                <div class="min-w-0">
                                    <div>{{ path.name }}</div>
                                    <div
                                        class="truncate text-xs text-muted-foreground"
                                        :title="path.path"
                                    >
                                        {{ path.path }}
                                    </div>
                                    <div
                                        v-if="!path.writable || path.public"
                                        class="text-xs text-destructive"
                                    >
                                        {{
                                            !path.writable
                                                ? 'Not writable'
                                                : 'Inside the public folder'
                                        }}
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Index slots</CardTitle>
                        <CardDescription>
                            Capacity shared by every site.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="Object.keys(stardust.slots).length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            None yet. StarDust adds them as fields become
                            filterable.
                        </p>
                        <dl
                            v-else
                            class="grid grid-cols-[1fr_auto] gap-y-1 text-sm"
                        >
                            <template
                                v-for="(count, status) in stardust.slots"
                                :key="status"
                            >
                                <dt class="text-muted-foreground capitalize">
                                    {{ status }}
                                </dt>
                                <dd class="tabular-nums">{{ count }}</dd>
                            </template>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
