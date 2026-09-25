<script setup lang="ts">
import { AlertTriangle, CheckCircle2, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { UploadItem } from '@/composables/useFileUploads';
import { formatSize } from '@/lib/files';

defineProps<{
    items: UploadItem[];
    busy: boolean;
}>();

const emit = defineEmits<{
    cancel: [item: UploadItem];
    clear: [];
}>();
</script>

<template>
    <div v-if="items.length > 0" class="rounded-lg border p-3 text-sm">
        <div class="mb-2 flex items-center justify-between">
            <span class="font-medium">
                {{ busy ? 'Uploading…' : 'Uploads' }}
            </span>
            <Button
                v-if="!busy"
                variant="ghost"
                size="sm"
                @click="emit('clear')"
            >
                Clear
            </Button>
        </div>
        <ul class="grid max-h-56 gap-2 overflow-y-auto">
            <li v-for="item in items" :key="item.id" class="grid gap-1">
                <div class="flex items-center gap-2">
                    <Spinner
                        v-if="item.status === 'uploading'"
                        class="shrink-0"
                    />
                    <CheckCircle2
                        v-else-if="item.status === 'done'"
                        class="size-4 shrink-0 text-green-600 dark:text-green-400"
                    />
                    <AlertTriangle
                        v-else-if="item.status === 'error'"
                        class="size-4 shrink-0 text-destructive"
                    />
                    <span v-else class="size-4 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                    <span
                        class="shrink-0 text-xs text-muted-foreground tabular-nums"
                    >
                        {{ formatSize(item.size) }}
                    </span>
                    <button
                        v-if="
                            item.status === 'queued' ||
                            item.status === 'uploading'
                        "
                        type="button"
                        class="shrink-0 text-muted-foreground hover:text-foreground"
                        :aria-label="`Cancel ${item.name}`"
                        @click="emit('cancel', item)"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <div
                    v-if="item.status === 'uploading'"
                    class="h-1 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full bg-primary transition-[width]"
                        :style="{
                            width: `${Math.round(item.progress * 100)}%`,
                        }"
                    />
                </div>
                <p v-if="item.error" class="pl-6 text-xs text-destructive">
                    {{ item.error }}
                </p>
            </li>
        </ul>
    </div>
</template>
