<?php

namespace App\Entries;

use App\Models\SchemaModel;
use App\StarDust\StarDustService;
use StarDust\Read\Entry;

/**
 * Entry reads and writes for a model, on top of the thin StarDustService
 * wrapper. Owns the conventions StarDust itself knows nothing about: the
 * reserved trash flag every entry carries.
 */
class EntryManager
{
    /**
     * StarDust has no restore, so "deleted" from the list is a flag, not
     * StarDust's own delete. Reserved: SchemaDiff refuses a builder-entered
     * key starting with "_", so this never collides with a real field.
     */
    public const TRASH_FIELD = '_trashed';

    public function __construct(private readonly StarDustService $stardust) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public function create(SchemaModel $model, array $fields): int
    {
        // Written before the field is promoted to filterable, the same
        // order the builder itself uses for a brand-new field: an
        // unmapped value now, backfilled once the promotion below queues
        // it, rather than a filterable-but-unassigned field racing its
        // own first write for a slot.
        $entryId = $this->stardust->writeEntry($model->stardust_model_id, [
            ...$fields,
            self::TRASH_FIELD => 0,
        ]);

        $this->stardust->ensureTrashField($model->stardust_model_id);

        return $entryId;
    }

    public function get(SchemaModel $model, int $entryId): ?Entry
    {
        return $this->stardust->getEntry($model->stardust_model_id, $entryId);
    }

    /**
     * A full replace, but never of the trash flag: whatever it currently
     * is carries forward, since a plain update would otherwise clear it.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(SchemaModel $model, int $entryId, array $fields): void
    {
        $trashed = $this->get($model, $entryId)?->fields[self::TRASH_FIELD] ?? 0;

        $this->stardust->updateEntry($model->stardust_model_id, $entryId, [
            ...$fields,
            self::TRASH_FIELD => $trashed,
        ]);
    }
}
