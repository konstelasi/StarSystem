<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

type ModuleNotices = {
    safeMode: 'env' | 'file' | null;
    failed: { slug: string; name: string; error: string | null }[];
};

/**
 * Tells the install's owner that safe mode is on, or that a module was
 * switched off because it failed.
 */
const page = usePage();

const notices = computed(
    () => page.props.moduleNotices as ModuleNotices | null | undefined,
);
</script>

<template>
    <div
        v-if="notices && (notices.safeMode || notices.failed.length > 0)"
        class="flex flex-col gap-2 px-4 pt-4"
    >
        <Alert v-if="notices.safeMode">
            <AlertTriangle />
            <AlertTitle>Safe mode is on: no modules are loaded</AlertTitle>
            <AlertDescription>
                <p v-if="notices.safeMode === 'file'">
                    To turn it off, delete the <code>safe-mode</code> file in
                    the storage folder.
                </p>
                <p v-else>
                    To turn it off, set
                    <code>STARSYSTEM_SAFE_MODE=false</code> in
                    <code>.env</code>.
                </p>
            </AlertDescription>
        </Alert>

        <Alert v-if="notices.failed.length > 0" variant="destructive">
            <AlertTriangle />
            <AlertTitle>
                {{
                    notices.failed.length === 1
                        ? 'A module was switched off because it failed'
                        : `${notices.failed.length} modules were switched off because they failed`
                }}
            </AlertTitle>
            <AlertDescription>
                <ul class="grid gap-1">
                    <li v-for="module in notices.failed" :key="module.slug">
                        <span class="font-medium">{{ module.name }}:</span>
                        {{ module.error }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>
    </div>
</template>
