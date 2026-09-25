<script setup lang="ts">
import { Head, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Circle, RotateCcw } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { updates } from '@/routes/admin';
import { check, retry, start, step } from '@/routes/admin/updates';

type Status = 'running' | 'stalled' | 'done' | 'rolled_back' | 'failed';

type UpdateState = {
    status: Status;
    mode: 'update' | 'rollback';
    step: string | null;
    from: string;
    to: string | null;
    waiting: string | null;
    error: string | null;
    failed_step: string | null;
    rollback_error: string | null;
    started_at: string;
    finished_at: string | null;
};

const props = defineProps<{
    current: string;
    latest: {
        release: { version: string; published_at: string | null } | null;
        error: string | null;
        checkedAt: string | null;
    };
    available: boolean;
    state: UpdateState | null;
    steps: { update: string[]; rollback: string[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Updates', href: updates() }],
    },
});

const labels: Record<string, string> = {
    download: 'Download and verify the new version',
    extract: 'Unpack it',
    check: 'Check it can run on this server',
    pause: 'Pause background work and show the maintenance page',
    swap: 'Put the new files in place',
    migrate: 'Update the database',
    bootstrap: 'Update the content engine',
    caches: 'Clear caches',
    finish: 'Bring the site back',
    unmigrate: 'Undo the database changes',
    restore: 'Put the previous files back',
};

const state = ref<UpdateState | null>(props.state);
const lostContact = ref(false);
const http = useHttp();
const page = usePage();
const startForm = useForm({ version: props.latest.release?.version ?? '' });
const checkForm = useForm({});

const active = computed(
    () =>
        state.value?.status === 'running' || state.value?.status === 'stalled',
);

const stepList = computed(() =>
    state.value?.mode === 'rollback'
        ? props.steps.rollback
        : props.steps.update,
);

const stepStatus = (name: string): 'done' | 'current' | 'todo' => {
    const current = state.value?.step;

    if (!current) {
        return 'done';
    }

    const list = stepList.value;

    if (list.indexOf(name) < list.indexOf(current)) {
        return 'done';
    }

    return name === current ? 'current' : 'todo';
};

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

type StepResponse = { state: UpdateState | null; busy: boolean };

// One step per request, each well inside a shared host's time limit. A
// request that fails outright (the host killed it, say) is retried: every
// step picks up where the last attempt stopped.
const drive = async () => {
    let failures = 0;
    lostContact.value = false;

    while (state.value?.status === 'running') {
        try {
            const result = (await http.post(step.url())) as StepResponse;
            state.value = result.state;
            failures = 0;

            if (result.busy || result.state?.waiting) {
                await sleep(2000);
            }
        } catch {
            failures++;

            if (failures >= 5) {
                lostContact.value = true;

                return;
            }

            await sleep(3000);
        }
    }

    if (state.value?.status !== 'stalled') {
        // The version and the files behind this page may have changed.
        router.reload();
    }
};

const tryAgain = async () => {
    if (state.value?.status === 'stalled') {
        state.value = ((await http.post(retry.url())) as StepResponse).state;
    }

    drive();
};

onMounted(() => {
    if (state.value?.status === 'running') {
        drive();
    }
});

const formatTime = (iso: string) => new Date(iso).toLocaleString();
const updateError = computed(
    () => (page.props.errors as Record<string, string>).update,
);
</script>

<template>
    <Head title="Updates" />

    <div class="flex max-w-3xl flex-1 flex-col gap-4 p-4">
        <Alert v-if="updateError" variant="destructive">
            <AlertTriangle />
            <AlertTitle>The update didn't start</AlertTitle>
            <AlertDescription>{{ updateError }}</AlertDescription>
        </Alert>

        <Alert v-if="state?.status === 'done'" data-test="update-done">
            <CheckCircle2 />
            <AlertTitle>Updated to {{ state.to }}</AlertTitle>
            <AlertDescription>
                StarSystem was updated from {{ state.from
                }}<template v-if="state.finished_at">
                    on {{ formatTime(state.finished_at) }}</template
                >.
            </AlertDescription>
        </Alert>

        <Alert
            v-else-if="state?.status === 'rolled_back'"
            variant="destructive"
            data-test="update-rolled-back"
        >
            <RotateCcw />
            <AlertTitle>The update to {{ state.to }} was undone</AlertTitle>
            <AlertDescription>
                <p>
                    A step failed, so StarSystem put version
                    {{ state.from }} back and your site is running as before.
                </p>
                <p class="font-mono text-xs break-all">{{ state.error }}</p>
            </AlertDescription>
        </Alert>

        <Alert
            v-else-if="state?.status === 'failed'"
            variant="destructive"
            data-test="update-failed"
        >
            <AlertTriangle />
            <AlertTitle
                >The update to {{ state.to }} didn't go ahead</AlertTitle
            >
            <AlertDescription>
                <p>Nothing on your site was changed.</p>
                <p class="font-mono text-xs break-all">{{ state.error }}</p>
            </AlertDescription>
        </Alert>

        <Card v-if="active && state">
            <CardHeader>
                <CardTitle>
                    {{
                        state.mode === 'rollback'
                            ? `Undoing the update to ${state.to}`
                            : `Updating to ${state.to}`
                    }}
                </CardTitle>
                <CardDescription>
                    Keep this page open until it finishes. Visitors see a
                    maintenance page for a minute or two.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <ul class="grid gap-3 text-sm">
                    <li
                        v-for="name in stepList"
                        :key="name"
                        class="flex items-start gap-3"
                    >
                        <CheckCircle2
                            v-if="stepStatus(name) === 'done'"
                            class="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400"
                        />
                        <AlertTriangle
                            v-else-if="
                                stepStatus(name) === 'current' &&
                                state.status === 'stalled'
                            "
                            class="mt-0.5 size-4 shrink-0 text-destructive"
                        />
                        <Spinner
                            v-else-if="stepStatus(name) === 'current'"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        <Circle
                            v-else
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div>
                            {{ labels[name] ?? name }}
                            <p
                                v-if="
                                    stepStatus(name) === 'current' &&
                                    state.waiting === 'tick'
                                "
                                class="text-muted-foreground"
                            >
                                Waiting for background work that's already
                                running to finish.
                            </p>
                        </div>
                    </li>
                </ul>

                <Alert
                    v-if="state.mode === 'rollback' && state.error"
                    variant="destructive"
                >
                    <AlertTriangle />
                    <AlertTitle>
                        A step failed, so the previous version is being put back
                    </AlertTitle>
                    <AlertDescription>
                        <p class="font-mono text-xs break-all">
                            {{ state.error }}
                        </p>
                    </AlertDescription>
                </Alert>

                <Alert v-if="state.status === 'stalled'" variant="destructive">
                    <AlertTriangle />
                    <AlertTitle
                        >Putting the previous version back stopped</AlertTitle
                    >
                    <AlertDescription>
                        <p>
                            The site stays in maintenance mode until this
                            finishes. If trying again doesn't help, send this
                            message to your host's support desk:
                        </p>
                        <p class="font-mono text-xs break-all">
                            {{ state.rollback_error }}
                        </p>
                    </AlertDescription>
                </Alert>

                <Alert v-if="lostContact" variant="destructive">
                    <AlertTriangle />
                    <AlertTitle>The server stopped answering</AlertTitle>
                    <AlertDescription>
                        Nothing is lost: trying again carries on from the last
                        step that finished.
                    </AlertDescription>
                </Alert>

                <Button
                    v-if="state.status === 'stalled' || lostContact"
                    class="self-start"
                    :disabled="http.processing"
                    @click="tryAgain"
                >
                    <Spinner v-if="http.processing" />
                    Try again
                </Button>
            </CardContent>
        </Card>

        <Card v-else>
            <CardHeader>
                <CardTitle>StarSystem {{ current }}</CardTitle>
                <CardDescription>
                    <template v-if="latest.error">{{ latest.error }}</template>
                    <template v-else-if="available && latest.release">
                        Version {{ latest.release.version }} is
                        available<template v-if="latest.release.published_at">
                            (released
                            {{
                                formatTime(latest.release.published_at)
                            }})</template
                        >.
                    </template>
                    <template v-else>This is the latest version.</template>
                    <template v-if="latest.checkedAt">
                        Checked {{ formatTime(latest.checkedAt) }}.
                    </template>
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p v-if="available" class="text-sm text-muted-foreground">
                    Back up your database before updating. If a step fails,
                    StarSystem puts the current version back by itself, but a
                    backup covers what it can't.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="available && latest.release"
                        data-test="start-update"
                        :disabled="startForm.processing"
                        @click="startForm.post(start.url())"
                    >
                        <Spinner v-if="startForm.processing" />
                        Update to {{ latest.release.version }}
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="checkForm.processing"
                        @click="checkForm.post(check.url())"
                    >
                        <Spinner v-if="checkForm.processing" />
                        Check again
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
