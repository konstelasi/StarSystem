<?php

namespace App\Models;

use App\Sites\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A logged schema operation, so a half-finished builder save can be shown
 * and retried.
 *
 * Laravel and StarDust writes can't share a transaction, so each operation
 * of a save (a batch) is logged before StarDust is called. A failure marks
 * that operation failed and the rest of the batch blocked; a retry replays
 * them from the stored payload.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $batch
 * @property int|null $model_id
 * @property string $op
 * @property string|null $field_id
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SchemaChange extends Model
{
    use BelongsToSite;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Not attempted, because an earlier operation of its batch failed. */
    public const BLOCKED = 'blocked';

    /** Statuses a retry picks up. */
    public const UNFINISHED = [self::PENDING, self::FAILED, self::BLOCKED];

    protected $table = 'ss_schema_changes';

    protected $fillable = ['batch', 'model_id', 'op', 'field_id', 'payload', 'status', 'attempts', 'error', 'applied_at'];

    protected $attributes = [
        'status' => self::PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'model_id' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
