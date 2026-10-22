<?php

namespace App\Frontend;

use App\Entries\EntryManager;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Exception\FieldNotIndexedException;
use StarDust\Exception\InvalidCursorException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\Entry;
use StarDust\Read\EntryPage;

/**
 * The one place that decides which entries a visitor may see, and the only
 * way the front end reads them. An entry is public when it is not in the
 * trash and, when its model's row names a "published" checkbox, when that
 * box is ticked.
 *
 * Visitors have no account, so this never asks what a user may do. It reads
 * through EntryManager, which sees the current site only.
 *
 * StarDust refuses a filter on a field it hasn't finished indexing, with
 * one exception while the field has no slot yet and another while it fills
 * one. A row can only be saved once its fields are indexed, so that happens
 * only if a field was changed since. The read then finds nothing and the
 * exception is reported, never shown to the visitor.
 */
class PublicEntries
{
    /**
     * How many entries a slug lookup reads. A slug is unique among the
     * entries not in the trash, but StarDust compares text without regard to
     * case, so a few near matches may come back and the exact one is picked.
     */
    private const LOOKUP = 10;

    public function __construct(private readonly EntryManager $entries) {}

    /**
     * The public entry with this slug, or null.
     */
    public function bySlug(SchemaModel $model, FrontendRoute $route, string $slug): ?Entry
    {
        $slugKey = $this->key($model, $route->slugField);
        $filters = $this->filters($model, $route);

        if ($slugKey === null || $filters === null) {
            return null;
        }

        try {
            $page = $this->entries->page($model, match: [LeafNode::local($slugKey, 'eq', $slug), ...$filters], pageSize: self::LOOKUP);
        } catch (FieldNotIndexedException|FieldNotFilterableException $e) {
            report($e);

            return null;
        }

        foreach ($page->rows as $entry) {
            if (($entry->fields[$slugKey] ?? null) === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * One page of the model's public entries, newest first.
     *
     * @throws InvalidCursorException when $cursor isn't one StarDust issued
     */
    public function page(SchemaModel $model, FrontendRoute $route, ?string $cursor, int $size = 12): EntryPage
    {
        $filters = $this->filters($model, $route);

        if ($filters === null) {
            return new EntryPage([], null, $size);
        }

        try {
            return $this->entries->page($model, $cursor, match: $filters, pageSize: $size);
        } catch (FieldNotIndexedException|FieldNotFilterableException $e) {
            report($e);

            return new EntryPage([], null, $size);
        }
    }

    /**
     * Whether another entry that is not in the trash already has the slug,
     * published or not. $exceptId is the entry being saved, so it doesn't
     * clash with itself.
     */
    public function slugTaken(SchemaModel $model, FrontendRoute $route, string $slug, ?int $exceptId): bool
    {
        $slugKey = $this->key($model, $route->slugField);

        if ($slugKey === null) {
            return false;
        }

        try {
            $page = $this->entries->page($model, match: LeafNode::local($slugKey, 'eq', $slug), pageSize: self::LOOKUP);
        } catch (FieldNotIndexedException|FieldNotFilterableException $e) {
            report($e);

            return false;
        }

        foreach ($page->rows as $entry) {
            if ($entry->id !== $exceptId && ($entry->fields[$slugKey] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an entry already read may be shown, for a link from another
     * entry. The same rule as a lookup, applied to the stored values.
     */
    public function isVisible(Entry $entry, SchemaModel $model, FrontendRoute $route): bool
    {
        if ($this->entries->isTrashed($entry)) {
            return false;
        }

        if ($route->publishedField === null) {
            return true;
        }

        $publishedKey = $this->key($model, $route->publishedField);

        return $publishedKey !== null && (int) ($entry->fields[$publishedKey] ?? 0) === 1;
    }

    /**
     * The filters that keep drafts out, or null when the row's published
     * field is gone and nothing can be shown safely.
     *
     * @return list<LeafNode>|null
     */
    private function filters(SchemaModel $model, FrontendRoute $route): ?array
    {
        if ($route->publishedField === null) {
            return [];
        }

        $publishedKey = $this->key($model, $route->publishedField);

        return $publishedKey === null ? null : [LeafNode::local($publishedKey, 'eq', 1)];
    }

    /**
     * The key of the model's field with this uuid, or null.
     */
    private function key(SchemaModel $model, string $uuid): ?string
    {
        /** @var SchemaField|null $field */
        $field = $model->fields->firstWhere('id', $uuid);

        return $field?->key;
    }
}
