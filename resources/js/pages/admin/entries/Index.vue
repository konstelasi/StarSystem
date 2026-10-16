<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Blocks,
    FileText,
    PlusCircle,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DeleteEntryDialog from '@/components/entries/DeleteEntryDialog.vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities } from '@/composables/useAbilities';
import { cellText, EMPTY_CELL } from '@/fields/display';
import { relativeTime } from '@/lib/time';
import { builder, index as models } from '@/routes/admin/models';
import {
    create,
    edit,
    index,
    restore,
    trash,
} from '@/routes/admin/models/entries';
import { store as moveToTrash } from '@/routes/admin/models/entries/trash';
import type { FieldSpecJson } from '@/types/schema';

type EntryRow = {
    id: number;
    values: Record<string, unknown>;
    /** Names of the files this row's file columns point at, by uuid. */
    file_names: Record<string, string>;
    created_at: string;
    trashed_at: string | null;
};

const props = defineProps<{
    model: { id: number; label: string };
    /** The model's active entries, or its trash. */
    view: 'active' | 'trash';
    /** The list's fields, as full specs so each value shows by its type. */
    columns: FieldSpecJson[];
    entries: EntryRow[];
    nextCursor: string | null;
    /** How long an entry stays in the trash before it is deleted for good. */
    trashDays: number;
    /** False until the model has fields, so there's nothing to fill in. */
    hasFields: boolean;
}>();

const { can } = useAbilities();

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

const description = computed(() =>
    props.view === 'trash'
        ? `Entries moved out of ${props.model.label}. Restore one to use it again. Entries in the trash are deleted for good after ${props.trashDays} days.`
        : 'The entries in this model.',
);

/** How the delete dialog names an entry: its first column, if it has one. */
function entryName(entry: EntryRow): string | null {
    const first = props.columns[0];
    const shown = first ? cell(entry, first) : EMPTY_CELL;

    return shown === EMPTY_CELL ? null : shown;
}

function cell(entry: EntryRow, column: FieldSpecJson): string {
    return cellText(column, entry.values[column.key], entry.file_names);
}

/** Whether a cell has no value, so the list can show it muted. */
function isEmpty(entry: EntryRow, column: FieldSpecJson): boolean {
    return cell(entry, column) === EMPTY_CELL;
}

/** A color cell's swatch, when the value is a color at all. */
function swatch(entry: EntryRow, column: FieldSpecJson): string | null {
    const value = entry.values[column.key];

    return column.type === 'color' &&
        typeof value === 'string' &&
        /^#[0-9a-f]{3,8}$/i.test(value)
        ? value
        : null;
}
</script>

<template>
    <Head :title="inTrash ? `${model.label}: trash` : model.label" />

    <PageBody>
        <PageHeader :title="inTrash ? 'Trash' : model.label" :description>
            <template #actions>
                <Button v-if="inTrash" variant="secondary" as-child>
                    <Link :href="index(model.id)">
                        <ArrowLeft />
                        Back to entries
                    </Link>
                </Button>
                <template v-else-if="hasFields">
                    <Button variant="ghost" as-child>
                        <Link :href="trash(model.id)">
                            <Trash2 />
                            Trash
                        </Link>
                    </Button>
                    <Button v-if="can('entries.create')" as-child>
                        <Link :href="create(model.id)">
                            <PlusCircle />
                            New entry
                        </Link>
                    </Button>
                </template>
            </template>
        </PageHeader>

        <Card v-if="!hasFields && !inTrash">
            <CardHeader class="items-center text-center">
                <Blocks class="size-8 text-muted-foreground" />
                <CardTitle>This model has no fields yet</CardTitle>
                <CardDescription>
                    Entries are filled in through the model's fields. Add some
                    first.
                </CardDescription>
                <Button v-if="can('models.update')" as-child class="mt-2">
                    <Link :href="builder(model.id)"
                        >Add fields in the builder</Link
                    >
                </Button>
            </CardHeader>
        </Card>

        <Card v-else-if="entries.length === 0 && !nextCursor">
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
            class="data-table rounded-lg border"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th
                            v-for="(column, position) in columns"
                            :key="column.key"
                            class="px-4 py-2"
                            :class="{ 'max-sm:hidden': position > 0 }"
                        >
                            {{ column.label }}
                        </th>
                        <th class="px-4 py-2 max-sm:hidden">
                            {{ inTrash ? 'Trashed' : 'Created' }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="align-top"
                    >
                        <td
                            v-for="(column, position) in columns"
                            :key="column.key"
                            class="px-4 py-2"
                            :class="{ 'max-sm:hidden': position > 0 }"
                        >
                            <span
                                v-if="swatch(entry, column)"
                                class="mr-1.5 inline-block size-3 rounded-sm border align-[-0.1em]"
                                :style="{
                                    backgroundColor: swatch(entry, column)!,
                                }"
                                aria-hidden="true"
                            />
                            <Link
                                v-if="position === 0 && !inTrash"
                                :href="
                                    edit({ model: model.id, entry: entry.id })
                                "
                                class="font-medium underline-offset-4 hover:underline"
                                :class="{
                                    'text-muted-foreground': isEmpty(
                                        entry,
                                        column,
                                    ),
                                }"
                            >
                                {{ cell(entry, column) }}
                            </Link>
                            <span
                                v-else
                                :class="{
                                    'text-muted-foreground': isEmpty(
                                        entry,
                                        column,
                                    ),
                                }"
                            >
                                {{ cell(entry, column) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap max-sm:hidden">
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
                                    v-if="can('entries.restore')"
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
                                    <RotateCcw />
                                    <span class="max-sm:sr-only">Restore</span>
                                </Button>
                                <DeleteEntryDialog
                                    v-if="can('entries.delete')"
                                    :model-id="model.id"
                                    :entry-id="entry.id"
                                    :name="entryName(entry)"
                                />
                            </template>
                            <Button
                                v-else-if="can('entries.trash')"
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
                                <span class="max-sm:sr-only"
                                    >Move to trash</span
                                >
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
    </PageBody>
</template>
