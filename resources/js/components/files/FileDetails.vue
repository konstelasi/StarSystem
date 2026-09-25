<script setup lang="ts">
import { Copy, ExternalLink, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FileThumb from '@/components/files/FileThumb.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    deleteFile,
    FileApiError,
    formatSize,
    normalizeFolder,
    updateFile,
} from '@/lib/files';
import type { FileChanges, StoredFile } from '@/types/files';

/**
 * The selected file: preview, the fields people change (name, alt text,
 * folder), the facts, and delete behind a confirmation.
 */
const props = defineProps<{
    file: StoredFile;
    folders: string[];
}>();

const emit = defineEmits<{
    updated: [file: StoredFile];
    deleted: [uuid: string];
    close: [];
}>();

const baseName = (file: StoredFile) =>
    file.name.slice(0, file.name.length - file.extension.length - 1);

const name = ref('');
const alt = ref('');
const folder = ref('');
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const confirmingDelete = ref(false);
const deleting = ref(false);

watch(
    () => props.file,
    (file) => {
        name.value = baseName(file);
        alt.value = file.alt ?? '';
        folder.value = file.folder;
        errors.value = {};
    },
    { immediate: true },
);

const changes = computed<FileChanges>(() => {
    const result: FileChanges = {};

    if (name.value.trim() !== baseName(props.file)) {
        result.name = name.value.trim();
    }

    if (alt.value.trim() !== (props.file.alt ?? '')) {
        result.alt = alt.value.trim() === '' ? null : alt.value.trim();
    }

    if (normalizeFolder(folder.value) !== props.file.folder) {
        result.folder = normalizeFolder(folder.value);
    }

    return result;
});

const dirty = computed(() => Object.keys(changes.value).length > 0);

const inlinePreview = computed(
    () =>
        ['image', 'video', 'audio'].includes(props.file.kind) ||
        props.file.mime === 'application/pdf',
);

async function save() {
    saving.value = true;
    errors.value = {};

    try {
        emit('updated', await updateFile(props.file.uuid, changes.value));
        toast.success('Changes saved.');
    } catch (e) {
        if (e instanceof FileApiError && Object.keys(e.errors).length > 0) {
            errors.value = Object.fromEntries(
                Object.entries(e.errors).map(([key, messages]) => [
                    key,
                    messages[0],
                ]),
            );
        } else {
            toast.error(
                e instanceof FileApiError ? e.message : 'Saving failed.',
            );
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    deleting.value = true;

    try {
        await deleteFile(props.file.uuid);
        confirmingDelete.value = false;
        emit('deleted', props.file.uuid);
        toast.success(`"${props.file.name}" was deleted.`);
    } catch (e) {
        toast.error(e instanceof FileApiError ? e.message : 'Deleting failed.');
    } finally {
        deleting.value = false;
    }
}

async function copyUrl() {
    try {
        await navigator.clipboard.writeText(props.file.url);
        toast.success('Link copied.');
    } catch {
        toast.error('Your browser blocked copying. Select the link instead.');
    }
}

const formatDate = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <aside class="grid content-start gap-4 rounded-xl border p-4">
        <div class="flex items-start justify-between gap-2">
            <h2 class="min-w-0 font-medium break-words">{{ file.name }}</h2>
            <Button
                variant="ghost"
                size="icon-sm"
                aria-label="Close details"
                @click="emit('close')"
            >
                <X />
            </Button>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <video
                v-if="file.kind === 'video'"
                :src="file.url"
                controls
                preload="metadata"
                class="w-full bg-black"
            />
            <div v-else-if="file.kind === 'audio'" class="grid gap-2 p-3">
                <FileThumb :file="file" class="h-24 rounded" />
                <audio :src="file.url" controls preload="none" class="w-full" />
            </div>
            <a
                v-else-if="file.kind === 'image'"
                :href="file.url"
                target="_blank"
                rel="noopener"
            >
                <img
                    :src="file.url"
                    :alt="file.alt ?? ''"
                    class="max-h-72 w-full bg-muted object-contain"
                />
            </a>
            <FileThumb v-else :file="file" class="h-32" />
        </div>

        <form class="grid gap-3" @submit.prevent="save">
            <div class="grid gap-1.5">
                <Label for="file-name">Name</Label>
                <div class="flex items-center gap-2">
                    <Input id="file-name" v-model="name" maxlength="240" />
                    <span class="shrink-0 text-sm text-muted-foreground"
                        >.{{ file.extension }}</span
                    >
                </div>
                <p v-if="errors.name" class="text-sm text-destructive">
                    {{ errors.name }}
                </p>
            </div>

            <div v-if="file.kind === 'image'" class="grid gap-1.5">
                <Label for="file-alt">Alt text</Label>
                <textarea
                    id="file-alt"
                    v-model="alt"
                    rows="3"
                    maxlength="1000"
                    placeholder="Describe the image for people who can't see it"
                    class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                />
                <p v-if="errors.alt" class="text-sm text-destructive">
                    {{ errors.alt }}
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="file-folder">Folder</Label>
                <Input
                    id="file-folder"
                    v-model="folder"
                    list="file-folder-options"
                    placeholder="Top level"
                />
                <datalist id="file-folder-options">
                    <option v-for="path in folders" :key="path" :value="path" />
                </datalist>
                <p v-if="errors.folder" class="text-sm text-destructive">
                    {{ errors.folder }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    Type a path such as "Photos/2026" to move the file. New
                    folders are created as you type them.
                </p>
            </div>

            <Button type="submit" :disabled="!dirty || saving">
                {{ saving ? 'Saving…' : 'Save changes' }}
            </Button>
        </form>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-muted-foreground">Type</dt>
            <dd class="break-all">{{ file.mime }}</dd>
            <dt class="text-muted-foreground">Size</dt>
            <dd>{{ formatSize(file.size) }}</dd>
            <template v-if="file.width && file.height">
                <dt class="text-muted-foreground">Dimensions</dt>
                <dd>{{ file.width }} × {{ file.height }} px</dd>
            </template>
            <dt class="text-muted-foreground">Uploaded</dt>
            <dd>
                {{ formatDate(file.createdAt) }}
                <template v-if="file.uploadedBy">
                    by {{ file.uploadedBy }}</template
                >
            </dd>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" @click="copyUrl">
                <Copy /> Copy link
            </Button>
            <Button variant="outline" size="sm" as-child>
                <a :href="file.url" target="_blank" rel="noopener">
                    <ExternalLink />
                    {{ inlinePreview ? 'Open' : 'Download' }}
                </a>
            </Button>
            <Button
                variant="outline"
                size="sm"
                class="text-destructive"
                @click="confirmingDelete = true"
            >
                <Trash2 /> Delete
            </Button>
        </div>

        <Dialog v-model:open="confirmingDelete">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete "{{ file.name }}"?</DialogTitle>
                    <DialogDescription>
                        Pages that link to or show this file will show a broken
                        link or image. Deleted files are removed for good after
                        a while.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="deleting"
                        @click="remove"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete file' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </aside>
</template>
