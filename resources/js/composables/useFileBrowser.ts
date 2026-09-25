import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { childFolders, FileApiError, listFiles } from '@/lib/files';
import type { FileKind, StoredFile, UploadLimits } from '@/types/files';

/**
 * Browsing state shared by the media library and the file picker: the
 * current folder, the search, the loaded page of files and the folders.
 *
 * Folders are virtual. One created here lives only in this browser until
 * a file is uploaded into it, then the server lists it like any other.
 */
export function useFileBrowser(
    options: {
        kinds?: Ref<FileKind[] | undefined>;
        perPage?: number;
    } = {},
) {
    const folder = ref('');
    const search = ref('');
    const files = ref<StoredFile[]>([]);
    const serverFolders = ref<string[]>([]);
    const localFolders = ref<string[]>([]);
    const limits = ref<UploadLimits | null>(null);
    const cursor = ref<string | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let request = 0;

    const searching = computed(() => search.value.trim() !== '');

    const folders = computed(() =>
        [...new Set([...serverFolders.value, ...localFolders.value])].sort(
            (a, b) =>
                a.localeCompare(b, undefined, {
                    numeric: true,
                    sensitivity: 'base',
                }),
        ),
    );

    const subfolders = computed(() =>
        searching.value ? [] : childFolders(folders.value, folder.value),
    );

    async function load(more = false): Promise<void> {
        const id = ++request;
        loading.value = true;
        error.value = null;

        try {
            const page = await listFiles({
                // A search looks through every folder.
                folder: searching.value ? undefined : folder.value,
                q: search.value.trim(),
                kinds: options.kinds?.value,
                cursor: more ? cursor.value : null,
                perPage: options.perPage,
            });

            if (id !== request) {
                return;
            }

            files.value = more ? [...files.value, ...page.data] : page.data;
            cursor.value = page.nextCursor;
            serverFolders.value = page.folders ?? serverFolders.value;
            limits.value = page.limits ?? limits.value;
        } catch (e) {
            if (id === request) {
                error.value =
                    e instanceof FileApiError
                        ? e.message
                        : 'The files could not be loaded.';
            }
        } finally {
            if (id === request) {
                loading.value = false;
            }
        }
    }

    watch(folder, () => void load());
    watchDebounced(search, () => void load(), { debounce: 300 });

    /** Shows a new or changed file, or drops it if it left this view. */
    function upsert(file: StoredFile): void {
        const visible = searching.value || file.folder === folder.value;
        const index = files.value.findIndex((f) => f.uuid === file.uuid);

        if (!visible) {
            if (index !== -1) {
                files.value.splice(index, 1);
            }
        } else if (index === -1) {
            files.value.unshift(file);
        } else {
            files.value[index] = file;
        }

        if (file.folder !== '' && !serverFolders.value.includes(file.folder)) {
            const segments = file.folder.split('/');
            serverFolders.value = [
                ...serverFolders.value,
                ...segments.map((_, i) => segments.slice(0, i + 1).join('/')),
            ];
        }
    }

    function remove(uuid: string): void {
        files.value = files.value.filter((file) => file.uuid !== uuid);
    }

    function addFolder(path: string): void {
        const segments = path.split('/');
        localFolders.value = [
            ...localFolders.value,
            ...segments.map((_, i) => segments.slice(0, i + 1).join('/')),
        ];
    }

    return {
        folder,
        search,
        searching,
        files,
        folders,
        subfolders,
        limits,
        cursor,
        loading,
        error,
        load,
        upsert,
        remove,
        addFolder,
    };
}
