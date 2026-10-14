<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { FileText, PlusCircle } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index as models } from '@/routes/admin/models';
import { create, index } from '@/routes/admin/models/entries';

type Column = { key: string; label: string };

type EntryRow = {
    id: number;
    values: Record<string, unknown>;
    created_at: string;
};

const props = defineProps<{
    model: { id: number; label: string };
    columns: Column[];
    entries: EntryRow[];
    nextCursor: string | null;
}>();

const loadingMore = ref(false);

/**
 * Appends the next page: the server sends `entries` as a merge prop. The
 * URL stays the same, so reloading the page starts from the newest again.
 */
function loadMore() {
    router.reload({
        data: { cursor: props.nextCursor },
        only: ['entries', 'nextCursor'],
        preserveUrl: true,
        onStart: () => {
            loadingMore.value = true;
        },
        onFinish: () => {
            loadingMore.value = false;
        },
    });
}

setLayoutProps({
    breadcrumbs: [
        { title: 'Models', href: models() },
        { title: props.model.label, href: index(props.model.id) },
    ],
});

const formatTime = (iso: string) => new Date(iso).toLocaleString();

function display(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length === 0 ? '—' : value.map(String).join(', ');
    }

    return String(value);
}
</script>

<template>
    <Head :title="model.label" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-0.5">
                <h2 class="text-xl font-semibold tracking-tight">
                    {{ model.label }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    The entries in this model.
                </p>
            </div>
            <Button as-child>
                <Link :href="create(model.id)">
                    <PlusCircle />
                    New entry
                </Link>
            </Button>
        </div>

        <Card v-if="entries.length === 0">
            <CardHeader class="items-center text-center">
                <FileText class="size-8 text-muted-foreground" />
                <CardTitle>No entries yet</CardTitle>
                <CardDescription>
                    Create the first one to get started.
                </CardDescription>
            </CardHeader>
        </Card>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-2 font-medium"
                        >
                            {{ column.label }}
                        </th>
                        <th class="px-4 py-2 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="border-t align-top"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-2"
                        >
                            {{ display(entry.values[column.key]) }}
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ formatTime(entry.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="entries.length > 0" class="flex justify-center">
            <Spinner v-if="loadingMore" class="size-5" />
            <Button v-else-if="nextCursor" variant="outline" @click="loadMore">
                Load more
            </Button>
        </div>
    </div>
</template>
