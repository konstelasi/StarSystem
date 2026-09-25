<script setup lang="ts">
import { ChevronRight, Folder } from '@lucide/vue';
import { computed } from 'vue';
import { folderName } from '@/lib/files';

/**
 * The path to the current folder as clickable crumbs, and the folders
 * inside it as tiles.
 */
const props = defineProps<{
    folder: string;
    subfolders: string[];
    searching?: boolean;
}>();

const emit = defineEmits<{
    navigate: [path: string];
}>();

const crumbs = computed(() => {
    const segments = props.folder === '' ? [] : props.folder.split('/');

    return [
        { path: '', name: folderName('') },
        ...segments.map((name, i) => ({
            path: segments.slice(0, i + 1).join('/'),
            name,
        })),
    ];
});
</script>

<template>
    <div class="grid gap-3">
        <nav
            aria-label="Folder"
            class="flex min-w-0 flex-wrap items-center gap-1 text-sm"
        >
            <template v-if="searching">
                <span class="text-muted-foreground">
                    Search results in every folder
                </span>
            </template>
            <template v-else>
                <template v-for="(crumb, i) in crumbs" :key="crumb.path">
                    <ChevronRight
                        v-if="i > 0"
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                    <button
                        v-if="i < crumbs.length - 1"
                        type="button"
                        class="rounded px-1 text-muted-foreground hover:text-foreground"
                        @click="emit('navigate', crumb.path)"
                    >
                        {{ crumb.name }}
                    </button>
                    <span v-else class="px-1 font-medium" aria-current="page">
                        {{ crumb.name }}
                    </span>
                </template>
            </template>
        </nav>

        <div
            v-if="subfolders.length > 0"
            class="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2"
        >
            <button
                v-for="path in subfolders"
                :key="path"
                type="button"
                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm hover:bg-accent"
                @click="emit('navigate', path)"
            >
                <Folder class="size-4 shrink-0 text-muted-foreground" />
                <span class="truncate">{{ folderName(path) }}</span>
            </button>
        </div>
    </div>
</template>
