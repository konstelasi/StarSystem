<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, XCircle } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import InstallLayout from '@/layouts/InstallLayout.vue';
import { database } from '@/routes/install';

defineOptions({ layout: InstallLayout });

defineProps<{
    checks: { key: string; label: string; ok: boolean; help: string | null }[];
    passed: boolean;
}>();
</script>

<template>
    <Head title="Install" />

    <div class="flex flex-col gap-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                Welcome to StarSystem
            </h1>
            <p class="text-sm text-muted-foreground">
                Installing takes about five minutes. First, a quick look at your
                hosting to make sure StarSystem can run here.
            </p>
        </header>

        <ul class="grid gap-3 text-sm">
            <li
                v-for="check in checks"
                :key="check.key"
                class="flex items-start gap-2"
            >
                <CheckCircle2
                    v-if="check.ok"
                    class="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400"
                />
                <XCircle
                    v-else
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <div class="min-w-0">
                    <div>{{ check.label }}</div>
                    <p v-if="check.help" class="text-muted-foreground">
                        {{ check.help }}
                    </p>
                </div>
            </li>
        </ul>

        <p v-if="!passed" class="text-sm text-muted-foreground">
            A few things need changing before StarSystem can be installed.
            Change them, then check again.
        </p>

        <Button v-if="passed" as-child class="w-full">
            <Link :href="database()" data-test="continue">Continue</Link>
        </Button>
        <Button
            v-else
            class="w-full"
            variant="outline"
            @click="router.reload()"
        >
            Check again
        </Button>
    </div>
</template>
