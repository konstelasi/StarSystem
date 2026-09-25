<?php

namespace App\Files\Http;

use App\Files\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file as the admin and the file picker see it. The uuid is the id; the
 * numeric id and the stored path stay on the server.
 *
 * @mixin File
 */
class FileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var File $file */
        $file = $this->resource;

        return [
            'uuid' => $file->uuid,
            'name' => $file->original_name,
            'folder' => $file->folder,
            'mime' => $file->mime,
            'kind' => $file->kind(),
            'extension' => $file->extension(),
            'size' => $file->size,
            'width' => $file->width,
            'height' => $file->height,
            'alt' => $file->alt,
            'sha256' => $file->sha256,
            'url' => $file->url(),
            'uploadedBy' => $file->uploader?->name,
            'createdAt' => $file->created_at->toIso8601String(),
            'updatedAt' => $file->updated_at->toIso8601String(),
        ];
    }
}
