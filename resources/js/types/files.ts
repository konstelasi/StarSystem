export type FileKind = 'image' | 'video' | 'audio' | 'document' | 'archive';

/** A file as the admin JSON API returns it (App\Files\Http\FileResource). */
export type StoredFile = {
    uuid: string;
    name: string;
    folder: string;
    mime: string;
    kind: FileKind;
    extension: string;
    size: number;
    width: number | null;
    height: number | null;
    alt: string | null;
    sha256: string;
    url: string;
    uploadedBy: string | null;
    createdAt: string;
    updatedAt: string;
};

/** App\Files\UploadLimits::toArray(). */
export type UploadLimits = {
    maxFileBytes: number;
    maxFileSize: string;
    bindingSetting: 'files.max_size' | 'upload_max_filesize' | 'post_max_size';
    configured: number;
    uploadMaxFilesize: number;
    postMaxSize: number;
    canDetectTypes: boolean;
};

export type FileListResponse = {
    data: StoredFile[];
    nextCursor: string | null;
    /** Every folder path in use; only on the first page. */
    folders: string[] | null;
    /** Only on the first page. */
    limits: UploadLimits | null;
};

export type FileListQuery = {
    /** Omit to search every folder; '' is the root. */
    folder?: string;
    q?: string;
    kinds?: FileKind[];
    uuids?: string[];
    cursor?: string | null;
    perPage?: number;
};

export type FileChanges = {
    name?: string;
    alt?: string | null;
    folder?: string;
};
