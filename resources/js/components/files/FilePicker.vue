<script setup lang="ts">
/**
 * <FilePicker> — a dialog for choosing files from the current site's media
 * library, with search, folders and upload. It returns file uuids; store
 * those, never URLs, since a file's URL includes its display name.
 *
 * Props
 * - open (v-model:open): whether the dialog shows.
 * - multiple (default false): allow choosing more than one file.
 * - max: most files that can be chosen when `multiple`; no limit if unset.
 * - accept: the kinds that may be chosen, e.g. ['image']; all if unset.
 *   Only matching files are listed, and uploads of other kinds are
 *   refused before they're sent.
 * - selected: uuids to show as chosen when the dialog opens.
 * - title (default "Choose a file" / "Choose files").
 * - allowUpload (default true): show Upload and accept dropped files.
 *   Uploads go into the folder being viewed and are chosen at once.
 *
 * Events
 * - select(uuids: string[], files: StoredFile[]): the person confirmed.
 *   `files` holds the details of every chosen file the picker has loaded.
 *   The dialog closes itself.
 * - cancel(): closed without confirming.
 * - update:open(open: boolean)
 *
 * Slots
 * - trigger: optional element that opens the dialog (rendered as-child).
 *
 * Example
 *   <FilePicker v-model:open="picking" :accept="['image']"
 *       :selected="value ? [value] : []"
 *       @select="(uuids) => (value = uuids[0] ?? null)">
 *       <template #trigger><Button>Choose image</Button></template>
 *   </FilePicker>
 */
import { Search, Upload } from '@lucide/vue';
import { computed, ref, toRef, useTemplateRef, watch } from 'vue';
import FileDropzone from '@/components/files/FileDropzone.vue';
import FileGrid from '@/components/files/FileGrid.vue';
import FolderNav from '@/components/files/FolderNav.vue';
import UploadProgress from '@/components/files/UploadProgress.vue';
import { Button } from '@/components/ui/button';
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
import { useFileBrowser } from '@/composables/useFileBrowser';
import { useFileUploads } from '@/composables/useFileUploads';
import { listFiles } from '@/lib/files';
import type { FileKind, StoredFile } from '@/types/files';

const props = withDefaults(
    defineProps<{
        multiple?: boolean;
        max?: number;
        accept?: FileKind[];
        selected?: string[];
        title?: string;
        allowUpload?: boolean;
    }>(),
    {
        multiple: false,
        max: undefined,
        accept: undefined,
        selected: () => [],
        title: undefined,
        allowUpload: true,
    },
);

// Works with or without v-model:open; the trigger slot opens it too.
const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    select: [uuids: string[], files: StoredFile[]];
    cancel: [];
}>();

defineSlots<{
    trigger?: () => unknown;
}>();

const browser = useFileBrowser({
    kinds: toRef(props, 'accept'),
    perPage: 30,
});
const { folder, search, searching, files, subfolders, loading } = browser;

const chosen = ref<string[]>([]);
const known = ref(new Map<string, StoredFile>());
const confirmed = ref(false);

const heading = computed(
    () => props.title ?? (props.multiple ? 'Choose files' : 'Choose a file'),
);

const acceptText = computed(() =>
    props.accept?.length ? `Only ${props.accept.join(', ')} files.` : '',
);

watch(files, (list) => {
    for (const file of list) {
        known.value.set(file.uuid, file);
    }
});

watch(
    open,
    async (isOpen) => {
        if (!isOpen) {
            return;
        }

        confirmed.value = false;
        chosen.value = [...props.selected];
        search.value = '';

        if (folder.value !== '') {
            folder.value = '';
        } else {
            void browser.load();
        }

        // Fetch details of preselected files that may live in other folders.
        const missing = props.selected.filter((uuid) => !known.value.has(uuid));

        if (missing.length > 0) {
            try {
                const page = await listFiles({ uuids: missing, perPage: 100 });
                page.data.forEach((file) => known.value.set(file.uuid, file));
            } catch {
                // The grid still works; only the summary lacks their names.
            }
        }
    },
    { immediate: true },
);

const uploads = useFileUploads(browser.limits, (file) => {
    known.value.set(file.uuid, file);
    browser.upsert(file);
    toggle(file);
});

const dropzone = useTemplateRef<InstanceType<typeof FileDropzone>>('dropzone');
const refused = ref<string | null>(null);

function upload(picked: File[]) {
    refused.value = null;

    // The server decides the real type; this only saves sending a video
    // to a picker that wants images.
    const allowed = picked.filter((file) => {
        if (!props.accept?.length) {
            return true;
        }

        const major = file.type.split('/')[0];
        const kind: FileKind =
            major === 'image' || major === 'video' || major === 'audio'
                ? major
                : /zip/.test(file.type)
                  ? 'archive'
                  : 'document';

        return props.accept.includes(kind);
    });

    if (allowed.length < picked.length) {
        refused.value = `Some files were skipped. ${acceptText.value}`;
    }

    uploads.add(props.multiple ? allowed : allowed.slice(0, 1), folder.value);
}

function toggle(file: StoredFile) {
    const index = chosen.value.indexOf(file.uuid);

    if (index !== -1) {
        chosen.value.splice(index, 1);

        return;
    }

    if (!props.multiple) {
        chosen.value = [file.uuid];

        return;
    }

    if (props.max === undefined || chosen.value.length < props.max) {
        chosen.value.push(file.uuid);
    }
}

function confirm() {
    confirmed.value = true;
    emit(
        'select',
        [...chosen.value],
        chosen.value
            .map((uuid) => known.value.get(uuid))
            .filter((file): file is StoredFile => file !== undefined),
    );
    open.value = false;
}

function onOpenChange(isOpen: boolean) {
    if (!isOpen && !confirmed.value) {
        emit('cancel');
    }

    open.value = isOpen;
}

function navigate(path: string) {
    search.value = '';
    folder.value = path;
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </DialogTrigger>

        <DialogContent class="flex max-h-[90vh] flex-col gap-4 sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>{{ heading }}</DialogTitle>
                <DialogDescription>
                    <template v-if="multiple && max">
                        Choose up to {{ max }}.
                    </template>
                    {{ acceptText }}
                    <template v-if="allowUpload">
                        Drop files here to upload them.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-48 flex-1">
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
                <Button
                    v-if="allowUpload"
                    variant="outline"
                    :disabled="browser.limits.value?.canDetectTypes === false"
                    @click="dropzone?.choose()"
                >
                    <Upload /> Upload
                </Button>
            </div>

            <UploadProgress
                :items="uploads.items.value"
                :busy="uploads.busy.value"
                @cancel="uploads.cancel"
                @clear="uploads.clearFinished"
            />
            <p v-if="refused" class="text-sm text-destructive">
                {{ refused }}
            </p>

            <FileDropzone
                ref="dropzone"
                class="min-h-48 flex-1 overflow-y-auto"
                :disabled="!allowUpload"
                :multiple="multiple"
                @files="upload"
            >
                <div class="grid gap-3 p-0.5">
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
                        view="grid"
                        compact
                        :selected="chosen"
                        @select="toggle"
                        @open="
                            (file) => {
                                toggle(file);
                                if (!multiple) confirm();
                            }
                        "
                    />
                    <p
                        v-else-if="!loading"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        {{
                            searching
                                ? `No files match "${search}".`
                                : 'No files here yet.'
                        }}
                    </p>

                    <div class="flex justify-center">
                        <Spinner v-if="loading" class="size-5" />
                        <Button
                            v-else-if="browser.cursor.value"
                            variant="outline"
                            size="sm"
                            @click="browser.load(true)"
                        >
                            Load more
                        </Button>
                    </div>
                </div>
            </FileDropzone>

            <DialogFooter class="items-center gap-2 sm:justify-between">
                <span class="text-sm text-muted-foreground">
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
                    <Button
                        :disabled="chosen.length === 0 || uploads.busy.value"
                        @click="confirm"
                    >
                        Choose
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
