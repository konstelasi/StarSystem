<?php

namespace App\Files;

use App\Models\User;
use App\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An uploaded file of one site. The uuid is its public id; the numeric id
 * never leaves the server, so file URLs can't be enumerated.
 *
 * Write through FileStore, which owns the bytes on disk. Deleting the model
 * only hides the file; the bytes stay until files:purge-trash.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $folder
 * @property string $original_name
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploaded_by
 * @property string|null $alt
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class File extends Model
{
    use BelongsToSite, HasUuids, SoftDeletes;

    protected $table = 'ss_files';

    protected $fillable = [
        'folder',
        'original_name',
        'path',
        'mime',
        'size',
        'sha256',
        'width',
        'height',
        'uploaded_by',
        'alt',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Only the uuid is generated; the primary key stays auto-increment.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The public URL. The name segment is for people and search engines;
     * only the uuid is used to find the file.
     */
    public function url(): string
    {
        return route('files.show', ['uuid' => $this->uuid, 'name' => $this->original_name]);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * image, video, audio, document or archive: what the admin UI groups
     * and filters by, and what decides inline versus download.
     */
    public function kind(): string
    {
        return FileKind::of($this->mime);
    }
}
