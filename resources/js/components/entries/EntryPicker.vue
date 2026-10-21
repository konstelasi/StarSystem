<script setup lang="ts">
/**
 * <EntryPicker> is a dialog for choosing entries of one model, for a
 * relation field to link to. It lists them newest first, each named by one
 * of its fields, and searches that name when the field is indexed text. It
 * returns entry ids. Store those, since a name can change.
 *
 * Props
 * - open (v-model:open): whether the dialog shows.
 * - modelId: the model whose entries are listed.
 * - labelField: uuid of the field that names those entries.
 * - multiple (default false): allow choosing more than one entry.
 * - max (default 50): most entries that can be chosen when `multiple`.
 * - selected: ids to show as chosen when the dialog opens.
 * - title (default "Choose an entry" / "Choose entries").
 *
 * Events
 * - select(items: { id: number; label: string }[]): the person confirmed.
 *   A selected id the picker never loaded comes back with an empty label,
 *   so keep the name you already know for it. The dialog closes itself.
 * - cancel(): closed without confirming.
 * - update:open(open: boolean)
 *
 * Slots
 * - trigger: optional element that opens the dialog (rendered as-child).
 *
 * Example
 *   <EntryPicker v-model:open="picking" :model-id="7" :label-field="name"
 *       :selected="value ? [value] : []"
 *       @select="(items) => (value = items[0]?.id ?? null)">
 *       <template #trigger><Button>Choose an entry</Button></template>
 *   </EntryPicker>
 */
import { Check, Search } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { lookupEntries } from '@/lib/entryLookup';
import type { LookupEntry } from '@/lib/entryLookup';

const props = withDefaults(
    defineProps<{
        modelId: number;
        labelField: string;
        multiple?: boolean;
        max?: number;
        selected: number[];
        title?: string;
    }>(),
    {
        multiple: false,
        max: 50,
        title: undefined,
    },
);

// Works with or without v-model:open; the trigger slot opens it too.
const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    select: [items: LookupEntry[]];
    cancel: [];
}>();

defineSlots<{
    trigger?: () => unknown;
}>();

const rows = ref<LookupEntry[]>([]);
const cursor = ref<string | null>(null);
// Unknown until the first page says whether the names can be searched.
const searchable = ref<boolean | null>(null);
const search = ref('');
// The search the rows on screen answer, so "no match" names that search and
// not whatever has been typed since.
const applied = ref('');
const loading = ref(false);
const failed = ref(false);

const chosen = ref<number[]>([]);
const confirmed = ref(false);
// Names loaded since the dialog opened, for the items `select` returns.
const names = new Map<number, string>();
let request = 0;
let lastMore = false;

const heading = computed(
    () =>
        props.title ?? (props.multiple ? 'Choose entries' : 'Choose an entry'),
);

const atMax = computed(
    () => props.multiple && chosen.value.length >= props.max,
);

const nameOf = (row: LookupEntry) => row.label || `Entry ${row.id}`;

async function load(more = false): Promise<void> {
    const id = ++request;
    lastMore = more;
    loading.value = true;
    failed.value = false;

    // A later page keeps the search its cursor was made for.
    if (!more) {
        applied.value = search.value.trim();
    }

    try {
        const page = await lookupEntries(props.modelId, {
            label: props.labelField,
            q: applied.value,
            cursor: more ? cursor.value : null,
        });

        // More typing, or closing and reopening, made this answer stale.
        if (id !== request) {
            return;
        }

        page.data.forEach((row) => names.set(row.id, row.label));
        rows.value = more ? [...rows.value, ...page.data] : page.data;
        cursor.value = page.nextCursor;
        searchable.value = page.searchable;
    } catch {
        if (id === request) {
            failed.value = true;

            // Rows of the search before this one would pass as its results.
            if (!more) {
                rows.value = [];
                cursor.value = null;
            }
        }
    } finally {
        if (id === request) {
            loading.value = false;
        }
    }
}

const retry = () => void load(lastMore);

watch(
    open,
    (isOpen) => {
        // Anything still on its way belongs to an earlier opening.
        request++;

        if (!isOpen) {
            loading.value = false;

            return;
        }

        confirmed.value = false;
        chosen.value = [...props.selected];
        names.clear();
        search.value = '';
        rows.value = [];
        cursor.value = null;
        searchable.value = null;
        void load();
    },
    { immediate: true },
);

// Typing the text away and back, or closing, needs no new request.
watchDebounced(
    search,
    () => {
        if (open.value && search.value.trim() !== applied.value) {
            void load();
        }
    },
    { debounce: 300 },
);

function toggle(row: LookupEntry) {
    const index = chosen.value.indexOf(row.id);

    if (index !== -1) {
        chosen.value.splice(index, 1);
    } else if (chosen.value.length < props.max) {
        chosen.value.push(row.id);
    }
}

function confirm(ids: number[]) {
    confirmed.value = true;
    emit(
        'select',
        ids.map((id) => ({ id, label: names.get(id) ?? '' })),
    );
    open.value = false;
}

function onOpenChange(isOpen: boolean) {
    if (!isOpen && !confirmed.value) {
        emit('cancel');
    }

    open.value = isOpen;
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </DialogTrigger>

        <DialogContent class="flex max-h-[90vh] flex-col gap-4 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ heading }}</DialogTitle>
                <DialogDescription>
                    {{
                        multiple
                            ? 'Tick the entries to link, then press Done.'
                            : 'Click an entry to link it.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="searchable" class="relative">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search by the start of a name"
                    class="pl-8"
                    aria-label="Search entries"
                />
            </div>
            <p
                v-else-if="searchable === false"
                class="text-sm text-muted-foreground"
            >
                Make the field that names these entries filterable to search
                them.
            </p>

            <div class="min-h-48 flex-1 overflow-y-auto">
                <ul v-if="rows.length > 0" class="grid gap-0.5 p-0.5">
                    <li v-for="row in rows" :key="row.id">
                        <label
                            v-if="multiple"
                            class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm hover:bg-accent/50 has-[:disabled]:cursor-not-allowed has-[:disabled]:text-muted-foreground"
                        >
                            <Checkbox
                                :model-value="chosen.includes(row.id)"
                                :disabled="atMax && !chosen.includes(row.id)"
                                @update:model-value="toggle(row)"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                nameOf(row)
                            }}</span>
                        </label>
                        <button
                            v-else
                            type="button"
                            class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm outline-none hover:bg-accent/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-[current=true]:bg-accent"
                            :aria-current="
                                chosen.includes(row.id) ? 'true' : undefined
                            "
                            @click="confirm([row.id])"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                nameOf(row)
                            }}</span>
                            <Check
                                v-if="chosen.includes(row.id)"
                                class="size-4 shrink-0 text-primary"
                            />
                        </button>
                    </li>
                </ul>

                <div
                    v-if="failed"
                    role="alert"
                    class="flex flex-col items-center gap-3 py-8 text-sm"
                >
                    <p class="text-destructive">Couldn't load entries.</p>
                    <Button variant="outline" size="sm" @click="retry">
                        Try again
                    </Button>
                </div>
                <p
                    v-else-if="!loading && rows.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {{
                        applied === ''
                            ? 'No entries yet.'
                            : `No entries match "${applied}".`
                    }}
                </p>
                <div
                    v-else-if="loading || cursor"
                    class="flex justify-center py-3"
                >
                    <Spinner v-if="loading" class="size-5" />
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        @click="load(true)"
                    >
                        Load more
                    </Button>
                </div>
            </div>

            <p v-if="atMax" class="text-sm text-muted-foreground">
                You can link up to {{ max }} entries.
            </p>

            <DialogFooter class="items-center gap-2">
                <span
                    v-if="multiple"
                    class="text-sm text-muted-foreground sm:mr-auto"
                    aria-live="polite"
                >
                    {{
                        chosen.length === 0
                            ? 'Nothing chosen'
                            : `${chosen.length} chosen`
                    }}
                </span>
                <div class="flex gap-2">
                    <Button variant="secondary" @click="onOpenChange(false)">
                        Cancel
                    </Button>
                    <Button v-if="multiple" @click="confirm(chosen)">
                        Done
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
