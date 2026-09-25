<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import InstallLayout from '@/layouts/InstallLayout.vue';

defineOptions({ layout: InstallLayout });

defineProps<{
    cron: string;
    tickUrl: string;
    loginUrl: string;
    email: string;
}>();

const copied = ref<string | null>(null);

const copy = async (key: string, text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        // Some browsers refuse clipboard access; the text stays selectable.
    }
};
</script>

<template>
    <Head title="Installed" />

    <div class="flex flex-col gap-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                StarSystem is installed
            </h1>
            <p class="text-sm text-muted-foreground">
                One last thing: StarSystem does some of its work in the
                background, such as making new fields searchable and finishing
                imports. Your host needs to start that work once a minute.
            </p>
        </header>

        <section class="grid gap-2 text-sm">
            <h2 class="font-medium">Add a cron job (recommended)</h2>
            <p class="text-muted-foreground">
                In your hosting control panel, open "Cron Jobs", choose "Once
                per minute", and paste this command. If it doesn't run, your
                host may need the full path to PHP instead of "php", such as
                "/usr/local/bin/php".
            </p>
            <div class="flex items-start gap-2">
                <code
                    class="min-w-0 flex-1 rounded-md bg-muted px-3 py-2 font-mono text-xs break-all"
                    data-test="cron"
                    >{{ cron }}</code
                >
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="copied === 'cron' ? 'Copied' : 'Copy'"
                    @click="copy('cron', cron)"
                >
                    <Check v-if="copied === 'cron'" />
                    <Copy v-else />
                </Button>
            </div>
        </section>

        <section class="grid gap-2 text-sm">
            <h2 class="font-medium">No cron jobs on your plan?</h2>
            <p class="text-muted-foreground">
                Use a free website-monitoring or URL-fetch service to open this
                address once a minute instead. Keep it private: anyone with it
                can make your site do background work.
            </p>
            <div class="flex items-start gap-2">
                <code
                    class="min-w-0 flex-1 rounded-md bg-muted px-3 py-2 font-mono text-xs break-all"
                    data-test="tick-url"
                    >{{ tickUrl }}</code
                >
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="copied === 'tick' ? 'Copied' : 'Copy'"
                    @click="copy('tick', tickUrl)"
                >
                    <Check v-if="copied === 'tick'" />
                    <Copy v-else />
                </Button>
            </div>
        </section>

        <p class="text-sm text-muted-foreground">
            Copy these now: this page isn't shown again. The Health page tells
            you if background work stops running.
        </p>

        <Button as-child class="w-full">
            <a :href="loginUrl">Log in as {{ email }}</a>
        </Button>
    </div>
</template>
