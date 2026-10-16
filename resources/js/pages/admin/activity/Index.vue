<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageBody from '@/components/PageBody.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { activity } from '@/routes/admin';

type Row = {
    id: number;
    at: string;
    /** Null when no one was signed in, as with a failed sign-in. */
    actor: string | null;
    action: string;
    label: string;
    subject: string | null;
    details: string[];
    ip: string | null;
};

type Filters = {
    person: number | null;
    group: string | null;
    from: string | null;
    to: string | null;
};

const props = defineProps<{
    rows: Row[];
    nextCursor: string | null;
    filters: Filters;
    people: { id: number; name: string }[];
    groups: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Activity', href: activity() }],
    },
});

const filtered = computed(() =>
    Object.values(props.filters).some((value) => value !== null),
);

/** A new visit, so the list starts again from the newest row. */
function apply(change: Partial<Filters>) {
    const next = { ...props.filters, ...change };
    const data = Object.fromEntries(
        Object.entries(next).filter(
            ([, value]) => value !== null && value !== '',
        ),
    );

    router.get(activity().url, data, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

const loadingMore = ref(false);

/**
 * Appends the next page: the server sends `rows` as a merge prop. The
 * address stays the same, so reloading the page starts from the newest.
 */
function loadMore() {
    router.reload({
        data: { cursor: props.nextCursor },
        only: ['rows', 'nextCursor'],
        preserveUrl: true,
        onStart: () => {
            loadingMore.value = true;
        },
        onFinish: () => {
            loadingMore.value = false;
        },
    });
}

function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return date.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year:
            date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    });
}

const clock = (iso: string) =>
    new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });

const full = (iso: string) => new Date(iso).toLocaleString();

/** The days the loaded rows fall on, newest first, each with its rows. */
const days = computed(() => {
    const groups: { key: string; label: string; rows: Row[] }[] = [];

    for (const row of props.rows) {
        const key = new Date(row.at).toDateString();
        const last = groups[groups.length - 1];

        if (last?.key === key) {
            last.rows.push(row);
        } else {
            groups.push({ key, label: dayLabel(row.at), rows: [row] });
        }
    }

    return groups;
});

/** "Rina changed someone's role", or just the label when there is no one. */
function sentence(row: Row): string {
    return row.actor
        ? row.label.charAt(0).toLowerCase() + row.label.slice(1)
        : row.label;
}

const failed = (row: Row) =>
    row.action === 'auth.failed' || row.action === 'auth.two_factor_failed';
</script>

<template>
    <Head title="Activity" />

    <PageBody>
        <PageHeader
            title="Activity"
            description="What people did on this site, newest first."
        />

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">Person</span>
                <Select
                    :model-value="String(filters.person ?? 'all')"
                    @update:model-value="
                        (value) =>
                            apply({
                                person: value === 'all' ? null : Number(value),
                            })
                    "
                >
                    <SelectTrigger class="w-44" aria-label="Person">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Anyone</SelectItem>
                        <SelectItem
                            v-for="person in people"
                            :key="person.id"
                            :value="String(person.id)"
                        >
                            {{ person.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">Kind</span>
                <Select
                    :model-value="filters.group ?? 'all'"
                    @update:model-value="
                        (value) =>
                            apply({
                                group: value === 'all' ? null : String(value),
                            })
                    "
                >
                    <SelectTrigger class="w-40" aria-label="Kind of event">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Everything</SelectItem>
                        <SelectItem
                            v-for="group in groups"
                            :key="group"
                            :value="group"
                        >
                            {{ group }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">From</span>
                <Input
                    type="date"
                    class="w-40"
                    aria-label="From date"
                    :model-value="filters.from ?? ''"
                    @update:model-value="
                        (value) => apply({ from: String(value) || null })
                    "
                />
            </div>

            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">To</span>
                <Input
                    type="date"
                    class="w-40"
                    aria-label="To date"
                    :model-value="filters.to ?? ''"
                    @update:model-value="
                        (value) => apply({ to: String(value) || null })
                    "
                />
            </div>

            <Button
                v-if="filtered"
                variant="ghost"
                @click="
                    apply({ person: null, group: null, from: null, to: null })
                "
            >
                Clear
            </Button>
        </div>

        <Card v-if="rows.length === 0">
            <CardHeader class="items-center text-center">
                <ScrollText class="size-8 text-muted-foreground" />
                <CardTitle>
                    {{ filtered ? 'Nothing matches' : 'Nothing recorded yet' }}
                </CardTitle>
                <CardDescription>
                    {{
                        filtered
                            ? 'Try a wider range or another person.'
                            : 'Sign-ins and changes to people and roles will show up here.'
                    }}
                </CardDescription>
            </CardHeader>
        </Card>

        <section v-for="day in days" :key="day.key" class="grid gap-2">
            <h2 class="text-sm text-muted-foreground">{{ day.label }}</h2>
            <ol class="divide-y rounded-lg border">
                <li
                    v-for="row in day.rows"
                    :key="row.id"
                    class="grid grid-cols-[3.25rem_1fr] gap-x-3 px-4 py-3 sm:grid-cols-[3.25rem_1fr_auto]"
                >
                    <time
                        class="pt-px text-sm text-muted-foreground tabular-nums"
                        :datetime="row.at"
                        :title="full(row.at)"
                    >
                        {{ clock(row.at) }}
                    </time>
                    <div class="min-w-0">
                        <p
                            class="text-sm"
                            :class="{ 'text-destructive': failed(row) }"
                        >
                            <span
                                v-if="row.actor"
                                class="font-medium text-foreground"
                            >
                                {{ row.actor }}
                            </span>
                            {{ sentence(row) }}
                        </p>
                        <p
                            v-if="row.subject || row.details.length > 0"
                            class="mt-0.5 text-sm break-words text-muted-foreground"
                        >
                            <span v-if="row.subject" class="text-foreground">
                                {{ row.subject }}
                            </span>
                            {{ row.details.join(', ') }}
                        </p>
                    </div>
                    <span
                        v-if="row.ip"
                        class="pt-px text-xs text-muted-foreground tabular-nums max-sm:hidden"
                    >
                        {{ row.ip }}
                    </span>
                </li>
            </ol>
        </section>

        <div v-if="nextCursor || loadingMore" class="flex justify-center">
            <Spinner v-if="loadingMore" class="size-5" />
            <Button v-else variant="outline" @click="loadMore">
                Load more
            </Button>
        </div>
    </PageBody>
</template>
