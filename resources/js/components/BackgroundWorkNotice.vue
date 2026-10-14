<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { health } from '@/routes/admin';

type BackgroundWork = { minutesSinceLast: number | null };

/**
 * Tells everyone signed in that StarDust's background work is late, so a
 * field stuck at "Indexing" has an explanation. The Health page explains
 * it in full, so the banner stays off there.
 */
const page = usePage();
const late = computed(
    () => page.props.backgroundWork as BackgroundWork | null | undefined,
);
const onHealth = computed(() => page.component === 'admin/Health');
</script>

<template>
    <div v-if="late && !onHealth" class="px-4 pt-4">
        <Alert variant="destructive">
            <AlertTriangle />
            <AlertTitle>Background work isn't running</AlertTitle>
            <AlertDescription>
                <p>
                    <template v-if="late.minutesSinceLast === null">
                        It hasn't run yet on this install.
                    </template>
                    <template v-else>
                        The last run was {{ late.minutesSinceLast }} minutes
                        ago.
                    </template>
                    New filterable fields stay at Indexing until it runs.
                </p>
                <Link :href="health()" class="underline underline-offset-4">
                    See Health
                </Link>
            </AlertDescription>
        </Alert>
    </div>
</template>
