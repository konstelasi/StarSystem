<script setup lang="ts">
import { Check } from '@lucide/vue';
import FileThumb from '@/components/files/FileThumb.vue';
import { formatSize } from '@/lib/files';
import { cn } from '@/lib/utils';
import type { StoredFile } from '@/types/files';

/**
 * Files as a grid of tiles or a list, shared by the media library and the
 * file picker. Clicking selects; double-click or Enter opens.
 */
defineProps<{
    files: StoredFile[];
    view: 'grid' | 'list';
    selected: string[];
    compact?: boolean;
}>();

const emit = defineEmits<{
    select: [file: StoredFile, event: MouseEvent | KeyboardEvent];
    open: [file: StoredFile];
}>();

const formatDate = (iso: string) => new Date(iso).toLocaleDateString();
</script>

<template>
    <div
        v-if="view === 'grid'"
        :class="
            cn(
                'grid gap-3',
                compact
                    ? 'grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))]'
                    : 'grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))]',
            )
        "
        role="listbox"
        aria-multiselectable="true"
    >
        <button
            v-for="file in files"
            :key="file.uuid"
            type="button"
            role="option"
            :aria-selected="selected.includes(file.uuid)"
            :title="file.name"
            :class="
                cn(
                    'group relative flex flex-col overflow-hidden rounded-lg border text-left transition outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    selected.includes(file.uuid)
                        ? 'border-primary ring-2 ring-primary'
                        : 'hover:border-foreground/30',
                )
            "
            @click="emit('select', file, $event)"
            @dblclick="emit('open', file)"
            @keydown.enter.prevent="emit('open', file)"
        >
            <FileThumb :file="file" class="aspect-square w-full" />
            <span class="truncate px-2 py-1.5 text-xs">{{ file.name }}</span>
            <span
                v-if="selected.includes(file.uuid)"
                class="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground"
            >
                <Check class="size-3.5" />
            </span>
        </button>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
            <thead class="text-left text-xs text-muted-foreground">
                <tr>
                    <th class="py-2 pr-4 pl-3 font-medium">Name</th>
                    <th class="py-2 pr-4 font-medium">Size</th>
                    <th v-if="!compact" class="py-2 pr-4 font-medium">Type</th>
                    <th v-if="!compact" class="py-2 pr-3 font-medium">
                        Uploaded
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="file in files"
                    :key="file.uuid"
                    tabindex="0"
                    :aria-selected="selected.includes(file.uuid)"
                    :class="
                        cn(
                            'cursor-pointer border-t outline-none focus-visible:bg-accent',
                            selected.includes(file.uuid)
                                ? 'bg-accent'
                                : 'hover:bg-accent/50',
                        )
                    "
                    @click="emit('select', file, $event)"
                    @dblclick="emit('open', file)"
                    @keydown.enter.prevent="emit('open', file)"
                    @keydown.space.prevent="emit('select', file, $event)"
                >
                    <td class="py-1.5 pr-4 pl-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <FileThumb
                                :file="file"
                                class="size-8 shrink-0 rounded [&_span]:hidden [&_svg]:size-4"
                            />
                            <span class="truncate">{{ file.name }}</span>
                        </div>
                    </td>
                    <td class="py-1.5 pr-4 whitespace-nowrap tabular-nums">
                        {{ formatSize(file.size) }}
                    </td>
                    <td
                        v-if="!compact"
                        class="py-1.5 pr-4 whitespace-nowrap text-muted-foreground"
                    >
                        {{ file.extension.toUpperCase() }}
                    </td>
                    <td
                        v-if="!compact"
                        class="py-1.5 pr-3 whitespace-nowrap text-muted-foreground"
                    >
                        {{ formatDate(file.createdAt) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
