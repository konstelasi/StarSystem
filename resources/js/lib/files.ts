import { destroy, index, store, update } from '@/routes/admin/api/files';
import type {
    FileChanges,
    FileListQuery,
    FileListResponse,
    StoredFile,
    UploadLimits,
} from '@/types/files';

/**
 * Client for the media library's JSON API. Every failure becomes a
 * FileApiError whose message can be shown to the person as is.
 */
export class FileApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'FileApiError';
    }
}

/** Laravel's encrypted CSRF cookie, which it accepts as X-XSRF-TOKEN. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function headers(json: boolean): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
        ...(json ? { 'Content-Type': 'application/json' } : {}),
    };
}

type ErrorBody = { message?: string; errors?: Record<string, string[]> };

function parseBody(text: string): ErrorBody {
    try {
        return JSON.parse(text) as ErrorBody;
    } catch {
        return {};
    }
}

/**
 * Turns an HTTP failure into words. A 413 comes from PHP's post_max_size,
 * before Laravel runs, so it is explained with the host's limit.
 */
export function errorFor(
    status: number,
    body: ErrorBody,
    limits?: UploadLimits | null,
): FileApiError {
    const errors = body.errors ?? {};
    const first = Object.values(errors)[0]?.[0];

    const message = (() => {
        switch (true) {
            case status === 0:
                return 'The connection dropped before the server answered. Check your connection and try again.';
            case status === 413:
                return limits
                    ? `This file is larger than your host allows (${limits.maxFileSize}).`
                    : 'This file is larger than your host allows.';
            case status === 422 && first !== undefined:
                return first;
            case status === 419:
                return 'Your session expired. Reload the page and try again.';
            case status === 401 || status === 403:
                return 'You are signed out. Sign in again to manage files.';
            case status === 404:
                return 'This file no longer exists. It may have been deleted.';
            case status >= 500:
                return 'The server ran into a problem. Try again, and if it keeps happening, check the error log.';
            default:
                return body.message ?? `The request failed (${status}).`;
        }
    })();

    return new FileApiError(message, status, errors);
}

async function send<T>(
    url: string,
    method: string,
    body?: unknown,
): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: method.toUpperCase(),
            headers: headers(body !== undefined),
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
        });
    } catch {
        throw errorFor(0, {});
    }

    const text = await response.text();

    if (!response.ok) {
        throw errorFor(response.status, parseBody(text));
    }

    return (text === '' ? undefined : JSON.parse(text)) as T;
}

export function listFiles(
    query: FileListQuery = {},
): Promise<FileListResponse> {
    const route = index({
        query: {
            folder: query.folder,
            q: query.q || undefined,
            kinds: query.kinds?.length ? query.kinds : undefined,
            uuids: query.uuids?.length ? query.uuids : undefined,
            cursor: query.cursor ?? undefined,
            per_page: query.perPage,
        },
    });

    return send<FileListResponse>(route.url, route.method);
}

export async function updateFile(
    uuid: string,
    changes: FileChanges,
): Promise<StoredFile> {
    const route = update(uuid);

    return (await send<{ data: StoredFile }>(route.url, route.method, changes))
        .data;
}

export async function deleteFile(uuid: string): Promise<void> {
    const route = destroy(uuid);

    await send<void>(route.url, route.method);
}

export type Upload = {
    promise: Promise<StoredFile>;
    abort: () => void;
};

/**
 * Uploads one file with progress. One file per request keeps each request
 * under the host's post_max_size and lets every file fail on its own.
 * XMLHttpRequest because fetch can't report upload progress.
 */
export function uploadFile(
    file: File,
    folder: string,
    onProgress: (fraction: number) => void,
    limits?: UploadLimits | null,
): Upload {
    const xhr = new XMLHttpRequest();
    const route = store();

    const promise = new Promise<StoredFile>((resolve, reject) => {
        const tooLarge = limits ? tooLargeMessage(file, limits) : null;

        if (tooLarge) {
            reject(new FileApiError(tooLarge, 413));

            return;
        }

        const form = new FormData();
        form.append('files[]', file, file.name);
        form.append('folder', folder);

        xhr.open(route.method.toUpperCase(), route.url);

        for (const [name, value] of Object.entries(headers(false))) {
            xhr.setRequestHeader(name, value);
        }

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        };

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                const body = JSON.parse(xhr.responseText) as {
                    data: StoredFile[];
                };
                onProgress(1);
                resolve(body.data[0]);

                return;
            }

            const error = errorFor(
                xhr.status,
                parseBody(xhr.responseText),
                limits,
            );
            // The batch has one file, so its error is under files.0.
            reject(error);
        };

        xhr.onerror = () => reject(errorFor(0, {}));
        xhr.onabort = () =>
            reject(new FileApiError('The upload was cancelled.', 0));

        xhr.send(form);
    });

    return { promise, abort: () => xhr.abort() };
}

/**
 * Checks the size before sending, so a 200 MB video isn't uploaded only
 * to be refused at the end.
 */
export function tooLargeMessage(
    file: File,
    limits: UploadLimits,
): string | null {
    if (file.size <= limits.maxFileBytes) {
        return null;
    }

    return limits.bindingSetting === 'files.max_size'
        ? `"${file.name}" is larger than the ${limits.maxFileSize} StarSystem allows.`
        : `"${file.name}" is larger than your host allows (${limits.maxFileSize}).`;
}

export function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} bytes`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let size = bytes / 1024;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    const rounded = size >= 10 ? Math.round(size).toString() : size.toFixed(1);

    return `${rounded.replace(/\.0$/, '')} ${units[unit]}`;
}

/** The immediate child folders of `parent` ('' is the root). */
export function childFolders(all: string[], parent: string): string[] {
    const prefix = parent === '' ? '' : `${parent}/`;
    const children = new Set<string>();

    for (const path of all) {
        if (path.startsWith(prefix) && path !== parent) {
            const rest = path.slice(prefix.length).split('/')[0];

            if (rest) {
                children.add(prefix + rest);
            }
        }
    }

    return [...children].sort((a, b) =>
        a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }),
    );
}

/** Mirrors App\Files\FolderPath::normalize() closely enough for the UI. */
export function normalizeFolder(path: string): string {
    return path
        .replace(/\\/g, '/')
        .split('/')
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '')
        .join('/');
}

export function folderName(path: string): string {
    return path === '' ? 'Media' : (path.split('/').pop() ?? path);
}
