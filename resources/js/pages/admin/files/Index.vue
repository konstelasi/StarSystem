<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    FolderPlus,
    LayoutGrid,
    List,
    Search,
    Upload,
} from '@lucide/vue';
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import FileDetails from '@/components/files/FileDetails.vue';
import FileDropzone from '@/components/files/FileDropzone.vue';
import FileGrid from '@/components/files/FileGrid.vue';
import FolderNav from '@/components/files/FolderNav.vue';
import UploadProgress from '@/components/files/UploadProgress.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
import { useFileBrowser } from '@/composables/useFileBrowser';
import { useFileUploads } from '@/composables/useFileUploads';
import { normalizeFolder } from '@/lib/files';
import { cn } from '@/lib/utils';
import { files as mediaLibrary } from '@/routes/admin';
import type { StoredFile, UploadLimits } from '@/types/files';

const props = defineProps<{
    limits: UploadLimits;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Media', href: mediaLibrary() }],
    },
});

const browser = useFileBrowser();
const { folder, search, searching, files, folders, subfolders, loading } =
    browser;

browser.limits.value = props.limits;

const selected = ref<StoredFile | null>(null);
const view = ref<'grid' | 'list'>('grid');

const uploads = useFileUploads(browser.limits, (file) => {
    browser.upsert(file);
    selected.value = file;
});

const dropzone = useTemplateRef<InstanceType<typeof FileDropzone>>('dropzone');

const limitText = computed(() => {
    const limits = browser.limits.value ?? props.limits;

    return limits.bindingSetting === 'files.max_size'
        ? `Up to ${limits.maxFileSize} per file.`
        : `Up to ${limits.maxFileSize} per file, the most your host allows.`;
});

// Keep the folder and view in the address, so a reload or a shared link
// opens the same place.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    folder.value = normalizeFolder(params.get('folder') ?? '');
    view.value = params.get('view') === 'list' ? 'list' : 'grid';

    if (folder.value === '') {
        void browser.load();
    }
});

watch([folder, view], () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('folder');
    url.searchParams.delete('view');

    if (folder.value !== '') {
        url.searchParams.set('folder', folder.value);
    }

    if (view.value === 'list') {
        url.searchParams.set('view', 'list');
    }

    window.history.replaceState(window.history.state, '', url);
    selected.value = null;
});

function navigate(path: string) {
    search.value = '';
    folder.value = path;
}

function select(file: StoredFile) {
    selected.value = selected.value?.uuid === file.uuid ? null : file;
}

function onUpdated(file: StoredFile) {
    browser.upsert(file);
    selected.value =
        file.folder === folder.value || searching.value ? file : null;
}

function onDeleted(uuid: string) {
    browser.remove(uuid);
    selected.value = null;
}

// New folder
const creatingFolder = ref(false);
const newFolderName = ref('');
const newFolderError = ref<string | null>(null);

function createFolder() {
    const name = newFolderName.value.trim();

    if (!/^[\p{L}\p{N}][\p{L}\p{N} _.,()&+'-]*$/u.test(name)) {
        newFolderError.value =
            'Use letters, numbers, spaces, dots, dashes and brackets, starting with a letter or number.';

        return;
    }

    const path = normalizeFolder(
        folder.value === '' ? name : `${folder.value}/${name}`,
    );
    browser.addFolder(path);
    creatingFolder.value = false;
    newFolderName.value = '';
    newFolderError.value = null;
    navigate(path);
}
</script>

<template>
    <Head title="Media" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Alert v-if="!limits.canDetectTypes" variant="destructive">
            <AlertTriangle />
            <AlertTitle>Uploads are off</AlertTitle>
            <AlertDescription>
                This server can't check what kind of file an upload is, so
                nothing can be uploaded. Ask your host to enable the PHP
                fileinfo extension.
            </AlertDescription>
        </Alert>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-48 flex-1 sm:max-w-xs">
                <Search
                    class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search all files"
                    class="pl-8"
                    aria-label="Search all files"
                />
            </div>

            <div class="ml-auto flex items-center gap-2">
                <div class="flex rounded-md border p-0.5">
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :class="cn(view === 'grid' && 'bg-accent')"
                        aria-label="Show as grid"
                        :aria-pressed="view === 'grid'"
                        @click="view = 'grid'"
                    >
                        <LayoutGrid />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        :class="cn(view === 'list' && 'bg-accent')"
                        aria-label="Show as list"
                        :aria-pressed="view === 'list'"
                        @click="view = 'list'"
                    >
                        <List />
                    </Button>
                </div>
                <Button
                    variant="outline"
                    :disabled="searching"
                    @click="creatingFolder = true"
                >
                    <FolderPlus /> New folder
                </Button>
                <Button
                    :disabled="!limits.canDetectTypes"
                    @click="dropzone?.choose()"
                >
                    <Upload /> Upload
                </Button>
            </div>
        </div>

        <UploadProgress
            :items="uploads.items.value"
            :busy="uploads.busy.value"
            @cancel="uploads.cancel"
            @clear="uploads.clearFinished"
        />

        <div class="flex flex-1 flex-col gap-4 lg:flex-row lg:items-start">
            <FileDropzone
                ref="dropzone"
                class="min-h-64 min-w-0 flex-1"
                :disabled="!limits.canDetectTypes"
                @files="(picked) => uploads.add(picked, folder)"
            >
                <div class="grid gap-4">
                    <FolderNav
                        :folder="folder"
                        :subfolders="subfolders"
                        :searching="searching"
                        @navigate="navigate"
                    />

                    <p
                        v-if="browser.error.value"
                        class="text-sm text-destructive"
                    >
                        {{ browser.error.value }}
                    </p>

                    <FileGrid
                        v-if="files.length > 0"
                        :files="files"
                        :view="view"
                        :selected="selected ? [selected.uuid] : []"
                        @select="select"
                        @open="(file) => (selected = file)"
                    />

                    <div
                        v-else-if="!loading"
                        class="flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-12 text-center text-sm text-muted-foreground"
                    >
                        <Upload class="size-6" />
                        <template v-if="searching">
                            No files match "{{ search }}".
                        </template>
                        <template v-else>
                            <p>This folder is empty.</p>
                            <p>
                                Drag files here or use Upload.
                                {{ limitText }}
                            </p>
                            <p v-if="subfolders.length === 0 && folder !== ''">
                                A new folder is kept once a file is in it.
                            </p>
                        </template>
                    </div>

                    <div class="flex justify-center">
                        <Spinner v-if="loading" class="size-5" />
                        <Button
                            v-else-if="browser.cursor.value"
                            variant="outline"
                            @click="browser.load(true)"
                        >
                            Load more
                        </Button>
                    </div>

                    <p
                        v-if="files.length > 0"
                        class="text-xs text-muted-foreground"
                    >
                        Drag files anywhere here to upload them to this folder.
                        {{ limitText }}
                    </p>
                </div>
            </FileDropzone>

            <FileDetails
                v-if="selected"
                :file="selected"
                :folders="folders"
                class="w-full shrink-0 lg:sticky lg:top-4 lg:w-80"
                @updated="onUpdated"
                @deleted="onDeleted"
                @close="selected = null"
            />
        </div>

        <Dialog v-model:open="creatingFolder">
            <DialogContent>
                <form class="grid gap-4" @submit.prevent="createFolder">
                    <DialogHeader>
                        <DialogTitle>New folder</DialogTitle>
                        <DialogDescription>
                            Folders only organise the library; links to files
                            don't change when files move. A new folder is kept
                            once a file is uploaded or moved into it.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-1.5">
                        <Label for="new-folder">Name</Label>
                        <Input
                            id="new-folder"
                            v-model="newFolderName"
                            maxlength="100"
                            autocomplete="off"
                        />
                        <p
                            v-if="newFolderError"
                            class="text-sm text-destructive"
                        >
                            {{ newFolderError }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancel</Button
                            >
                        </DialogClose>
                        <Button type="submit">Create</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
