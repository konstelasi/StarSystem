<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Circle } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import InstallLayout from '@/layouts/InstallLayout.vue';
import { admin } from '@/routes/install';
import { migrate, stardust } from '@/routes/install/setup';

defineOptions({ layout: InstallLayout });

type Task = 'migrate' | 'stardust';

const props = defineProps<{
    tasks: Record<Task, boolean>;
    failure: { task: Task; message: string } | null;
}>();

const labels: Record<Task, { title: string; description: string }> = {
    migrate: {
        title: 'Create the database tables',
        description: 'Where your pages, users and settings will live.',
    },
    stardust: {
        title: 'Prepare the content engine',
        description: 'StarDust, which stores and searches your content.',
    },
};

const routes = { migrate, stardust };

const running = ref<Task | null>(null);

const next = computed(
    () =>
        (Object.keys(props.tasks) as Task[]).find(
            (task) => !props.tasks[task],
        ) ?? null,
);

// One task per request keeps every request short, well inside the time
// limit shared hosts put on a page load.
const run = (task: Task) => {
    running.value = task;

    router.post(
        routes[task].url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                running.value = null;
            },
        },
    );
};

onMounted(() => {
    if (next.value && !props.failure) {
        run(next.value);
    }
});
</script>

<template>
    <Head title="Set up" />

    <div class="flex flex-col gap-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                Setting things up
            </h1>
            <p class="text-sm text-muted-foreground">
                Your database details work. StarSystem is now getting the
                database ready. Keep this page open; it only takes a moment.
            </p>
        </header>

        <ul class="grid gap-4 text-sm">
            <li
                v-for="(done, task) in tasks"
                :key="task"
                class="flex items-start gap-3"
            >
                <CheckCircle2
                    v-if="done"
                    class="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400"
                />
                <AlertTriangle
                    v-else-if="failure?.task === task"
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <Spinner
                    v-else-if="running === task"
                    class="mt-0.5 size-4 shrink-0"
                />
                <Circle
                    v-else
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div>
                    <div>{{ labels[task].title }}</div>
                    <p class="text-muted-foreground">
                        {{ labels[task].description }}
                    </p>
                </div>
            </li>
        </ul>

        <Alert v-if="failure" variant="destructive">
            <AlertTriangle />
            <AlertTitle>This step didn't finish</AlertTitle>
            <AlertDescription>
                <p>
                    Nothing is lost: trying again picks up where it stopped. If
                    it keeps failing, send this message to your host's support
                    desk:
                </p>
                <p class="font-mono text-xs break-all">{{ failure.message }}</p>
            </AlertDescription>
        </Alert>

        <Button v-if="next === null" as-child class="w-full">
            <Link :href="admin()" data-test="continue">Continue</Link>
        </Button>
        <Button
            v-else-if="failure"
            class="w-full"
            :disabled="running !== null"
            @click="run(failure.task)"
        >
            <Spinner v-if="running" />
            Try again
        </Button>
    </div>
</template>
