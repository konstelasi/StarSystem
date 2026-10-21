<?php

namespace App\Entries;

use App\Models\SchemaModel;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\StarDust\StarDustService;
use Carbon\CarbonImmutable;
use StarDust\Exception\InvalidCursorException;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\LeafNode;
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
     * StarDust has no restore, so moving an entry to the trash sets this
     * field instead of using StarDust's own delete: 0 while the entry is
     * active, the Unix time it was trashed once it is, which the purge
     * reads to empty the trash after the configured days.
     *
     * Reserved: SchemaDiff refuses a builder-entered key starting with "_",
     * so this never collides with a real field.
     */
    public const TRASH_FIELD = '_trashed';

    /**
     * Who made the entry and who last saved it, as user ids. Reserved the
     * same way as the trash flag. An entry written before these existed has
     * neither, and stays that way.
     */
    public const CREATED_BY_FIELD = '_created_by';

    public const UPDATED_BY_FIELD = '_updated_by';

    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly StarDustService $stardust,
        private readonly FieldTypeRegistry $types,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @param  int|null  $userId  Who is creating it. Without one the entry has no author.
     */
    public function create(SchemaModel $model, array $fields, ?int $userId = null): int
    {
        // Written before the field is promoted to filterable, the same
        // order the builder itself uses for a brand-new field: an
        // unmapped value now, backfilled once the promotion below queues
        // it, rather than a filterable-but-unassigned field racing its
        // own first write for a slot.
        $entryId = $this->stardust->writeEntry($model->stardust_model_id, [
            ...$fields,
            self::TRASH_FIELD => 0,
            ...($userId === null ? [] : [self::CREATED_BY_FIELD => $userId, self::UPDATED_BY_FIELD => $userId]),
        ]);

        $this->stardust->ensureTrashField($model->stardust_model_id);

        // Filterable, so a list can ask for the entries one person made.
        if ($userId !== null) {
            $this->stardust->ensureReservedIntField($model->stardust_model_id, self::CREATED_BY_FIELD);
        }

        return $entryId;
    }

    public function get(SchemaModel $model, int $entryId): ?Entry
    {
        return $this->stardust->getEntry($model->stardust_model_id, $entryId);
    }

    /**
     * Several of the model's entries by id, keyed by id. Trashed entries are
     * returned, and ids that aren't this model's on this site are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, Entry>
     */
    public function getMany(SchemaModel $model, array $ids): array
    {
        return $this->stardust->getEntries($model->stardust_model_id, $ids);
    }

    /**
     * Whether the model holds any entry, trashed ones included.
     */
    public function hasEntries(SchemaModel $model): bool
    {
        return $this->stardust->listEntries($model->stardust_model_id, pageSize: 1)->rows !== [];
    }

    /**
     * A full replace, but never of the reserved fields: the trash flag and
     * who made the entry carry forward, since a plain update would otherwise
     * clear them. Who saved it last becomes $userId, or stays as it was.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(SchemaModel $model, int $entryId, array $fields, ?int $userId = null): void
    {
        $existing = $this->get($model, $entryId);
        $createdBy = $existing === null ? null : $this->createdBy($existing);
        $updatedBy = $userId ?? ($existing === null ? null : $this->updatedBy($existing));

        $this->stardust->updateEntry($model->stardust_model_id, $entryId, [
            ...$fields,
            self::TRASH_FIELD => $existing?->fields[self::TRASH_FIELD] ?? 0,
            ...($createdBy === null ? [] : [self::CREATED_BY_FIELD => $createdBy]),
            ...($updatedBy === null ? [] : [self::UPDATED_BY_FIELD => $updatedBy]),
        ]);
    }

    /**
     * Moves an entry to the trash, carrying every other value forward.
     */
    public function trash(SchemaModel $model, Entry $entry): void
    {
        $this->stardust->updateEntry($model->stardust_model_id, $entry->id, [
            ...$entry->fields,
            self::TRASH_FIELD => CarbonImmutable::now()->getTimestamp(),
        ]);
    }

    /**
     * Takes an entry back out of the trash.
     */
    public function restore(SchemaModel $model, Entry $entry): void
    {
        $this->stardust->updateEntry($model->stardust_model_id, $entry->id, [
            ...$entry->fields,
            self::TRASH_FIELD => 0,
        ]);
    }

    /**
     * Deletes an entry for good: StarDust's delete has no restore. Callers
     * only offer it for entries already in the trash.
     */
    public function delete(SchemaModel $model, Entry $entry): void
    {
        $this->stardust->deleteEntry($model->stardust_model_id, $entry->id);
    }

    /**
     * The user id of whoever made the entry, or null for one written before
     * authors were kept.
     */
    public function createdBy(Entry $entry): ?int
    {
        return $this->userId($entry->fields[self::CREATED_BY_FIELD] ?? null);
    }

    /**
     * The user id of whoever saved the entry last, or null if unknown.
     */
    public function updatedBy(Entry $entry): ?int
    {
        return $this->userId($entry->fields[self::UPDATED_BY_FIELD] ?? null);
    }

    private function userId(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    public function isTrashed(Entry $entry): bool
    {
        return (int) ($entry->fields[self::TRASH_FIELD] ?? 0) > 0;
    }

    public function trashedAt(Entry $entry): ?CarbonImmutable
    {
        return $this->isTrashed($entry)
            ? CarbonImmutable::createFromTimestamp((int) $entry->fields[self::TRASH_FIELD])
            : null;
    }

    /**
     * One page of the model's active entries, or of its trash, newest
     * first, from $cursor (the previous page's nextCursor) on. Sorting by
     * id keeps StarDust on an index scan however many entries there are.
     *
     * StarDust refuses a filter on a field it hasn't finished indexing,
     * which the trash field is from a model's first entry until the next
     * tick, and the creator field is from the first entry anyone made. Until
     * then the page is read without that filter and the rows it would have
     * removed are dropped here, so a page can come back short; the cursor
     * still moves on correctly. $createdBy asks for one person's entries.
     *
     * @throws InvalidCursorException when $cursor isn't one StarDust issued
     */
    public function page(SchemaModel $model, ?string $cursor = null, bool $trashed = false, ?int $createdBy = null): EntryPage
    {
        $indexed = $this->trashIndexed($model);
        $authorIndexed = $createdBy === null || $this->createdByIndexed($model);

        $filters = array_values(array_filter([
            $indexed ? LeafNode::local(self::TRASH_FIELD, $trashed ? 'gt' : 'eq', 0) : null,
            $createdBy !== null && $authorIndexed ? LeafNode::local(self::CREATED_BY_FIELD, 'eq', $createdBy) : null,
        ]));

        $page = $this->stardust->listEntries(
            $model->stardust_model_id,
            filter: match (count($filters)) {
                0 => null,
                1 => $filters[0],
                default => new AndNode($filters),
            },
            sort: SortSpec::byId(SortDirection::Desc),
            pageSize: self::PAGE_SIZE,
            cursor: $cursor === null ? null : new Cursor($cursor),
        );

        if ($indexed && $authorIndexed) {
            return $page;
        }

        // Whatever StarDust couldn't filter yet is dropped here instead.
        return new EntryPage(
            array_values(array_filter($page->rows, fn (Entry $entry) => ($indexed || $this->isTrashed($entry) === $trashed)
                && ($authorIndexed || $this->createdBy($entry) === $createdBy))),
            $page->nextCursor,
            $page->pageSize,
        );
    }

    /**
     * Up to $limit of the model's entries trashed at or before $cutoff (a
     * Unix time), for the purge. Needs the trash field indexed.
     *
     * @return list<Entry>
     */
    public function trashedBefore(SchemaModel $model, int $cutoff, int $limit): array
    {
        return $this->stardust->listEntries(
            $model->stardust_model_id,
            filter: LeafNode::local(self::TRASH_FIELD, 'between', [1, $cutoff]),
            pageSize: $limit,
        )->rows;
    }

    /**
     * Whether StarDust can filter on the creator field yet.
     */
    public function createdByIndexed(SchemaModel $model): bool
    {
        return $this->stardust->describeModel($model->stardust_model_id)?->field(self::CREATED_BY_FIELD)->isIndexed ?? false;
    }

    /**
     * Whether StarDust can filter on the trash field yet.
     */
    public function trashIndexed(SchemaModel $model): bool
    {
        return $this->stardust->describeModel($model->stardust_model_id)?->field(self::TRASH_FIELD)->isIndexed ?? false;
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
