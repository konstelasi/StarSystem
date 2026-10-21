<?php

namespace App\Entries;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\User;
use App\Schema\FieldTypes\Core\RelationType;
use StarDust\Read\Entry;

/**
 * What the entry pages need to know about relation fields: which links an
 * account may see, whether the entries it picked can be linked, and the
 * text that names each linked entry.
 *
 * StarDust can't join models, so a linked entry is read by id, one read per
 * entry through EntryManager::getMany(). Every model lookup here goes
 * through SchemaModel, which sees the current site only, and an entry id of
 * another site reads as no entry at all.
 */
final class EntryLinks
{
    /** Most linked entries a list cell names, so a long list stays cheap. */
    public const PER_CELL = 5;

    private const TEXT_LENGTH = 80;

    public function __construct(
        private readonly EntryManager $entries,
        private readonly SiteAccess $access,
    ) {}

    /**
     * Keys of the relation fields whose linked model $user can't view. A
     * link with no model counts too, since there is nothing to pick from.
     *
     * @return list<string>
     */
    public function hiddenKeys(SchemaModel $model, User $user): array
    {
        $hidden = [];

        foreach ($model->fields as $field) {
            if ($field->type !== 'relation') {
                continue;
            }

            $target = RelationType::link($field->settings)['model'];

            if ($target === null || ! $this->access->allows($user, Ability::ENTRIES_VIEW, $target)) {
                $hidden[] = $field->key;
            }
        }

        return $hidden;
    }

    /**
     * Whether the entries a form picked can be linked, with one getMany()
     * per linked model. An id the entry already holds isn't asked about, so
     * a link to something since deleted doesn't stop other edits.
     *
     * Every failure says the same thing, with the first id that failed, so
     * the answer never shows which ids exist elsewhere.
     *
     * @param  array<string, mixed>  $fields  Normalized values, keyed by field key.
     * @return array<string, string> Messages keyed `fields.<key>`.
     */
    public function check(SchemaModel $model, array $fields, ?Entry $stored): array
    {
        $fresh = [];
        $asked = [];

        foreach ($model->fields as $field) {
            if ($field->type !== 'relation' || ! array_key_exists($field->key, $fields)) {
                continue;
            }

            $ids = array_values(array_diff(
                RelationType::ids($fields[$field->key]),
                RelationType::ids($stored?->fields[$field->key] ?? null),
            ));

            if ($ids === []) {
                continue;
            }

            $target = RelationType::link($field->settings)['model'];
            $fresh[$field->key] = [$target, $ids];

            if ($target !== null) {
                $asked[$target] = array_values(array_unique([...($asked[$target] ?? []), ...$ids]));
            }
        }

        $found = $this->read($asked);
        $errors = [];

        foreach ($fresh as $key => [$target, $ids]) {
            foreach ($ids as $id) {
                if ($target === null || ! isset($found[$target][$id])) {
                    $errors["fields.{$key}"] = "Entry {$id} can't be linked. Pick another or clear it.";

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * How each linked entry reads, by entry id. Entry ids are one sequence
     * across models, so one list can name the entries of several.
     *
     * A model several fields link to names its entries by the first of
     * those fields' naming field. An entry of a model $viewer can't view is
     * not read at all.
     *
     * @param  iterable<SchemaField>  $fields  The relation fields to resolve. Others are skipped.
     * @param  list<Entry>  $rows
     * @param  int|null  $perCell  Most ids to name from each field of a row, or all when null.
     * @return array<int, array{label: string, state: 'ok'|'trashed'|'missing'|'hidden'}>
     */
    public function labels(iterable $fields, array $rows, User $viewer, ?int $perCell = self::PER_CELL): array
    {
        $wanted = [];
        $namers = [];

        foreach ($fields as $field) {
            $link = RelationType::link($field->settings);

            if ($field->type !== 'relation' || $link['model'] === null) {
                continue;
            }

            $namers[$link['model']] ??= $link['label_field'];

            foreach ($rows as $row) {
                foreach (self::idsIn([$field], $row, $perCell) as $id) {
                    $wanted[$link['model']][$id] = $id;
                }
            }
        }

        if ($wanted === []) {
            return [];
        }

        $keys = SchemaField::query()->whereIn('id', array_values(array_filter($namers)))->pluck('key', 'id')->all();
        $labels = [];

        foreach ($wanted as $modelId => $ids) {
            $ids = array_values($ids);

            if (! $this->access->allows($viewer, Ability::ENTRIES_VIEW, $modelId)) {
                foreach ($ids as $id) {
                    $labels[$id] = ['label' => "Entry {$id}", 'state' => 'hidden'];
                }

                continue;
            }

            $found = $this->read([$modelId => $ids])[$modelId];
            $key = isset($namers[$modelId]) ? ($keys[$namers[$modelId]] ?? null) : null;

            foreach ($ids as $id) {
                $labels[$id] = match (true) {
                    ! isset($found[$id]) => ['label' => 'Deleted entry', 'state' => 'missing'],
                    $this->entries->isTrashed($found[$id]) => ['label' => self::text($found[$id], $key).' (in trash)', 'state' => 'trashed'],
                    default => ['label' => self::text($found[$id], $key), 'state' => 'ok'],
                };
            }
        }

        return $labels;
    }

    /**
     * The ids a row holds in these relation fields, at most $perCell from
     * each, or all of them when null.
     *
     * @param  iterable<SchemaField>  $fields  Others than relation fields are skipped.
     * @return list<int>
     */
    public static function idsIn(iterable $fields, Entry $row, ?int $perCell = null): array
    {
        $ids = [];

        foreach ($fields as $field) {
            if ($field->type !== 'relation') {
                continue;
            }

            $own = RelationType::ids($row->fields[$field->key] ?? null);

            array_push($ids, ...($perCell === null ? $own : array_slice($own, 0, $perCell)));
        }

        return array_values(array_unique($ids));
    }

    /**
     * The text that names an entry: the naming field's value cut to 80
     * characters with "…", or "Entry {id}" when it has none to show.
     */
    public static function text(Entry $entry, ?string $labelKey): string
    {
        $value = $labelKey === null ? null : ($entry->fields[$labelKey] ?? null);
        $text = is_string($value) || is_int($value) || is_float($value)
            ? trim((string) preg_replace('/\s+/u', ' ', (string) $value))
            : '';

        if ($text === '') {
            return "Entry {$entry->id}";
        }

        return mb_strlen($text) > self::TEXT_LENGTH ? mb_substr($text, 0, self::TEXT_LENGTH).'…' : $text;
    }

    /**
     * The entries behind these ids, by linked model id and then entry id.
     * A model that is gone or being deleted yields none, and is never
     * passed to StarDust, which would refuse it.
     *
     * @param  array<int, list<int>>  $ids  Linked model id => entry ids.
     * @return array<int, array<int, Entry>>
     */
    private function read(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $models = SchemaModel::query()->whereIn('id', array_keys($ids))->get()->keyBy('id');
        $entries = [];

        foreach ($ids as $modelId => $wanted) {
            $target = $models[$modelId] ?? null;

            $entries[$modelId] = $target === null || $target->isDeleting() ? [] : $this->entries->getMany($target, $wanted);
        }

        return $entries;
    }
}
