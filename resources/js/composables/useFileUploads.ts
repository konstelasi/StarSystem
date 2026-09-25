import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import { FileApiError, uploadFile } from '@/lib/files';
import type { StoredFile, UploadLimits } from '@/types/files';

export type UploadItem = {
    id: number;
    name: string;
    size: number;
    progress: number;
    status: 'queued' | 'uploading' | 'done' | 'error';
    error: string | null;
    abort: (() => void) | null;
};

let nextId = 1;

/**
 * A queue that uploads files one at a time. Shared hosts limit concurrent
 * PHP processes per account, so parallel uploads would mostly queue on the
 * server anyway, and one at a time keeps the progress honest.
 */
export function useFileUploads(
    limits: Ref<UploadLimits | null>,
    onUploaded: (file: StoredFile) => void,
) {
    const items = ref<UploadItem[]>([]);
    const queue: { item: UploadItem; file: File; folder: string }[] = [];
    let running = false;

    const busy = computed(() =>
        items.value.some(
            (item) => item.status === 'queued' || item.status === 'uploading',
        ),
    );

    function add(files: Iterable<File>, folder: string): void {
        for (const file of files) {
            const item: UploadItem = {
                id: nextId++,
                name: file.name,
                size: file.size,
                progress: 0,
                status: 'queued',
                error: null,
                abort: null,
            };

            items.value.push(item);
            // Work on the reactive copy so progress updates render.
            queue.push({
                item: items.value[items.value.length - 1],
                file,
                folder,
            });
        }

        void run();
    }

    async function run(): Promise<void> {
        if (running) {
            return;
        }

        running = true;

        for (let next = queue.shift(); next; next = queue.shift()) {
            const { item, file, folder } = next;

            if (item.status !== 'queued') {
                continue;
            }

            item.status = 'uploading';

            const upload = uploadFile(
                file,
                folder,
                (fraction) => (item.progress = fraction),
                limits.value,
            );
            item.abort = upload.abort;

            try {
                onUploaded(await upload.promise);
                item.status = 'done';
            } catch (error) {
                item.status = 'error';
                item.error =
                    error instanceof FileApiError
                        ? error.message
                        : 'The upload failed.';
            } finally {
                item.abort = null;
            }
        }

        running = false;
    }

    function cancel(item: UploadItem): void {
        if (item.status === 'queued') {
            item.status = 'error';
            item.error = 'Cancelled.';
        }

        item.abort?.();
    }

    function clearFinished(): void {
        items.value = items.value.filter(
            (item) => item.status === 'queued' || item.status === 'uploading',
        );
    }

    return { items, busy, add, cancel, clearFinished };
}
