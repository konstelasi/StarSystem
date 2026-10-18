<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type UserFilterValues = {
    q: string | null;
    role: number | null;
    status: string | null;
    sort: string;
};

const props = defineProps<{
    filters: UserFilterValues;
    roles: { id: number; name: string }[];
}>();

const emit = defineEmits<{ change: [change: Partial<UserFilterValues>] }>();

const search = ref(props.filters.q ?? '');

// Typing waits a moment, so each keystroke isn't a request.
const searchLater = useDebounceFn((value: string) => {
    const next = value.trim() === '' ? null : value.trim();

    if (next !== props.filters.q) {
        emit('change', { q: next });
    }
}, 300);

watch(search, (value) => searchLater(value));

// A link or the Clear button can change the search from outside.
watch(
    () => props.filters.q,
    (value) => {
        if ((value ?? '') !== search.value.trim()) {
            search.value = value ?? '';
        }
    },
);

const filtered = () =>
    props.filters.q !== null ||
    props.filters.role !== null ||
    props.filters.status !== null;

const STATUSES = [
    { value: 'active', label: 'Active' },
    { value: 'paused', label: 'Paused' },
    { value: 'no-two-factor', label: 'No two-factor' },
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative w-full sm:w-64">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                type="search"
                class="pl-9"
                placeholder="Search name or email"
                aria-label="Search people"
            />
        </div>

        <Select
            :model-value="String(filters.role ?? 'any')"
            @update:model-value="
                (value) =>
                    emit('change', {
                        role: value === 'any' ? null : Number(value),
                    })
            "
        >
            <SelectTrigger class="w-40" aria-label="Role">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="any">Any role</SelectItem>
                <SelectItem
                    v-for="role in roles"
                    :key="role.id"
                    :value="String(role.id)"
                >
                    {{ role.name }}
                </SelectItem>
            </SelectContent>
        </Select>

        <Select
            :model-value="filters.status ?? 'any'"
            @update:model-value="
                (value) =>
                    emit('change', {
                        status: value === 'any' ? null : String(value),
                    })
            "
        >
            <SelectTrigger class="w-44" aria-label="Status">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="any">Everyone</SelectItem>
                <SelectItem
                    v-for="status in STATUSES"
                    :key="status.value"
                    :value="status.value"
                >
                    {{ status.label }}
                </SelectItem>
            </SelectContent>
        </Select>

        <Button
            v-if="filtered()"
            variant="ghost"
            @click="emit('change', { q: null, role: null, status: null })"
        >
            Clear
        </Button>
    </div>
</template>
