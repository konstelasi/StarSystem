<?php

namespace App\Schema;

use App\Models\SchemaChange;
use App\Models\SchemaModel;
use Illuminate\Support\Collection;

/**
 * The outcome of a save, a model creation or a retry.
 *
 * "invalid" means nothing was written (see the preview's errors),
 * "unchanged" that there was nothing to do, "done" that every operation
 * was applied, and "failed" that one failed and the rest of the batch is
 * blocked until a retry.
 */
final class SaveResult
{
    public const INVALID = 'invalid';

    public const UNCHANGED = 'unchanged';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @param  array{operations: list<array<string, mixed>>, errors: list<array{field_uuid: ?string, message: string}>}  $preview
     * @param  Collection<int, SchemaChange>  $changes
     */
    public function __construct(
        public readonly string $status,
        public readonly ?SchemaModel $model,
        public readonly array $preview,
        public readonly ?string $batch = null,
        public readonly Collection $changes = new Collection,
    ) {}

    /**
     * @param  Collection<int, SchemaChange>  $changes
     */
    public static function fromBatch(SchemaModel $model, DiffResult $diff, string $batch, Collection $changes): self
    {
        $failed = $changes->contains(fn (SchemaChange $change) => $change->status !== SchemaChange::DONE);

        return new self($failed ? self::FAILED : self::DONE, $model->refresh(), $diff->toPreview(), $batch, $changes);
    }

    public function succeeded(): bool
    {
        return in_array($this->status, [self::DONE, self::UNCHANGED], true);
    }

    /**
     * The first failure, as the builder shows it.
     */
    public function error(): ?string
    {
        $failed = $this->changes->first(fn (SchemaChange $change) => $change->status === SchemaChange::FAILED);

        return $failed->error ?? ($this->preview['errors'][0]['message'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'model_id' => $this->model?->id,
            'schema_rev' => $this->model?->schema_rev,
            'batch' => $this->batch,
            'preview' => $this->preview,
            'changes' => $this->changes->map(fn (SchemaChange $change) => [
                'op' => $change->op,
                'field_uuid' => $change->field_id,
                'status' => $change->status,
                'error' => $change->error,
            ])->values()->all(),
        ];
    }
}
