<script setup lang="ts">
import {
    File as FileIcon,
    FileArchive,
    FileAudio,
    FileText,
    FileVideo,
} from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { StoredFile } from '@/types/files';

const props = defineProps<{
    file: StoredFile;
    class?: string;
}>();

const icon = computed(() => {
    switch (props.file.kind) {
        case 'video':
            return FileVideo;
        case 'audio':
            return FileAudio;
        case 'archive':
            return FileArchive;
        default:
            return props.file.mime === 'application/pdf' ? FileText : FileIcon;
    }
});
</script>

<template>
    <div
        :class="
            cn(
                'relative flex items-center justify-center overflow-hidden bg-muted',
                props.class,
            )
        "
    >
        <img
            v-if="file.kind === 'image'"
            :src="file.url"
            :alt="file.alt ?? ''"
            loading="lazy"
            decoding="async"
            class="size-full object-cover"
        />
        <div
            v-else
            class="flex flex-col items-center gap-1 text-muted-foreground"
        >
            <component :is="icon" class="size-8" />
            <span class="text-[10px] font-medium tracking-wide uppercase">
                {{ file.extension }}
            </span>
        </div>
    </div>
</template>
