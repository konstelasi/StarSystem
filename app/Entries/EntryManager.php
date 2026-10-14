<?php

namespace App\Entries;

use App\Models\SchemaModel;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\StarDust\StarDustService;
use StarDust\Exception\InvalidCursorException;
use StarDust\Read\Cursor;
use StarDust\Read\Entry;
use StarDust\Read\EntryPage;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;

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

    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly StarDustService $stardust,
        private readonly FieldTypeRegistry $types,
    ) {}

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

    /**
     * One page of the model's entries, newest first, from $cursor (the
     * previous page's nextCursor) on. Sorting by id keeps StarDust on an
     * index scan however many entries there are.
     *
     * @throws InvalidCursorException when $cursor isn't one StarDust issued
     */
    public function page(SchemaModel $model, ?string $cursor = null): EntryPage
    {
        return $this->stardust->listEntries(
            $model->stardust_model_id,
            sort: SortSpec::byId(SortDirection::Desc),
            pageSize: self::PAGE_SIZE,
            cursor: $cursor === null ? null : new Cursor($cursor),
        );
    }

    /**
     * Laravel validation rules for a model's fields, keyed by field key
     * (and "key.*" for list-shaped fields).
     *
     * @return array<string, list<mixed>>
     */
    public function rulesFor(SchemaModel $model): array
    {
        $rules = [];

        foreach ($model->fields as $field) {
            $type = $this->types->get($field->type);
            $settings = $type->resolveSettings($field->settings ?? []);

            foreach ($type->rules($settings, $field->required) as $suffix => $fieldRules) {
                $rules[$field->key.$suffix] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * The submitted values in the shape StarDust stores, dropping any key
     * that isn't one of the model's fields.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeFields(SchemaModel $model, array $input): array
    {
        $fields = [];

        foreach ($model->fields as $field) {
            if (! array_key_exists($field->key, $input)) {
                continue;
            }

            $type = $this->types->get($field->type);
            $settings = $type->resolveSettings($field->settings ?? []);

            $fields[$field->key] = $type->normalize($input[$field->key], $settings);
        }

        return $fields;
    }
}
