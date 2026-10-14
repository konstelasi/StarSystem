<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { ArrowLeft, FileText, PlusCircle, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import DeleteEntryDialog from '@/components/entries/DeleteEntryDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { relativeTime } from '@/lib/time';
import { index as models } from '@/routes/admin/models';
import {
    create,
    edit,
    index,
    restore,
    trash,
} from '@/routes/admin/models/entries';
import { store as moveToTrash } from '@/routes/admin/models/entries/trash';

type Column = { key: string; label: string };

type EntryRow = {
    id: number;
    values: Record<string, unknown>;
    created_at: string;
    trashed_at: string | null;
};

const props = defineProps<{
    model: { id: number; label: string };
    /** The model's active entries, or its trash. */
    view: 'active' | 'trash';
    columns: Column[];
    entries: EntryRow[];
    nextCursor: string | null;
}>();

const inTrash = computed(() => props.view === 'trash');

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

/** The entry whose action is running, to disable its buttons. */
const busy = ref<number | null>(null);

function post(url: string, entryId: number) {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = entryId;
            },
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

setLayoutProps({
    breadcrumbs: [
        { title: 'Models', href: models() },
        { title: props.model.label, href: index(props.model.id) },
        ...(props.view === 'trash'
            ? [{ title: 'Trash', href: trash(props.model.id) }]
            : []),
    ],
});

const formatTime = (iso: string) => new Date(iso).toLocaleString();

/** How the delete dialog names an entry: its first column, if it has one. */
function entryName(entry: EntryRow): string | null {
    const first = props.columns[0];
    const shown = first ? display(entry.values[first.key]) : '—';

    return shown === '—' ? null : shown;
}

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
    <Head :title="inTrash ? `${model.label}: trash` : model.label" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-0.5">
                <h2 class="text-xl font-semibold tracking-tight">
                    {{ inTrash ? 'Trash' : model.label }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    <template v-if="inTrash">
                        Entries moved out of {{ model.label }}. Restore one to
                        use it again.
                    </template>
                    <template v-else>The entries in this model.</template>
                </p>
            </div>
            <div class="flex gap-2">
                <Button v-if="inTrash" variant="secondary" as-child>
                    <Link :href="index(model.id)">
                        <ArrowLeft />
                        Back to entries
                    </Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="trash(model.id)">
                            <Trash2 />
                            Trash
                        </Link>
                    </Button>
                    <Button as-child>
                        <Link :href="create(model.id)">
                            <PlusCircle />
                            New entry
                        </Link>
                    </Button>
                </template>
            </div>
        </div>

        <Card v-if="entries.length === 0 && !nextCursor">
            <CardHeader class="items-center text-center">
                <component
                    :is="inTrash ? Trash2 : FileText"
                    class="size-8 text-muted-foreground"
                />
                <CardTitle>
                    {{ inTrash ? 'The trash is empty' : 'No entries yet' }}
                </CardTitle>
                <CardDescription v-if="!inTrash">
                    Create the first one to get started.
                </CardDescription>
            </CardHeader>
        </Card>

        <div
            v-else-if="entries.length > 0"
            class="overflow-x-auto rounded-lg border"
        >
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
                        <th class="px-4 py-2 font-medium">
                            {{ inTrash ? 'Trashed' : 'Created' }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="border-t align-top"
                    >
                        <td
                            v-for="(column, position) in columns"
                            :key="column.key"
                            class="px-4 py-2"
                        >
                            <Link
                                v-if="position === 0 && !inTrash"
                                :href="
                                    edit({ model: model.id, entry: entry.id })
                                "
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ display(entry.values[column.key]) }}
                            </Link>
                            <template v-else>
                                {{ display(entry.values[column.key]) }}
                            </template>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            <template v-if="inTrash && entry.trashed_at">
                                <span :title="formatTime(entry.trashed_at)">
                                    {{ relativeTime(entry.trashed_at) }}
                                </span>
                            </template>
                            <template v-else>
                                {{ formatTime(entry.created_at) }}
                            </template>
                        </td>
                        <td class="px-4 py-1 text-right whitespace-nowrap">
                            <template v-if="inTrash">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="busy === entry.id"
                                    @click="
                                        post(
                                            restore({
                                                model: model.id,
                                                entry: entry.id,
                                            }).url,
                                            entry.id,
                                        )
                                    "
                                >
                                    Restore
                                </Button>
                                <DeleteEntryDialog
                                    :model-id="model.id"
                                    :entry-id="entry.id"
                                    :name="entryName(entry)"
                                />
                            </template>
                            <Button
                                v-else
                                variant="ghost"
                                size="sm"
                                :disabled="busy === entry.id"
                                @click="
                                    post(
                                        moveToTrash({
                                            model: model.id,
                                            entry: entry.id,
                                        }).url,
                                        entry.id,
                                    )
                                "
                            >
                                <Trash2 />
                                Move to trash
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="nextCursor || loadingMore" class="flex justify-center">
            <Spinner v-if="loadingMore" class="size-5" />
            <Button v-else variant="outline" @click="loadMore">
                Load more
            </Button>
        </div>
    </div>
</template>
