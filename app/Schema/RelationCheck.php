<?php

namespace App\Schema;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Entries\EntryManager;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Models\User;
use App\Schema\FieldTypes\Core\RelationType;
use Closure;

/**
 * What a relation field may link to, checked when a model is saved. These
 * checks read other models and their entries, which the pure SchemaDiff
 * doesn't, so SchemaManager adds their errors to the diff's.
 *
 * Every lookup goes through SchemaModel and SchemaField, which see the
 * current site only. A link to another site's model reads as a link to a
 * model this site doesn't have.
 */
final class RelationCheck
{
    public function __construct(
        private readonly EntryManager $entries,
        private readonly SiteAccess $access,
    ) {}

    /**
     * @param  SchemaModel|null  $current  The model as it is saved, or null for one being created.
     * @param  User|null  $by  Who is saving. Without one, who may view the linked entries isn't asked.
     * @return list<array{field_uuid: ?string, message: string}>
     */
    public function errors(?SchemaModel $current, ModelSchema $desired, ?User $by = null): array
    {
        $saved = $current === null ? [] : $current->fields()->get()->keyBy('id')->all();
        $relations = array_values(array_filter($desired->fields, fn (FieldSpec $field) => $field->type === 'relation'));
        $targets = $this->targets($current, $relations);

        $errors = [];
        $refused = [];

        foreach ($relations as $field) {
            $message = $this->linkProblem($field, $saved[$field->uuid] ?? null, $current, $desired, $targets, $by);

            if ($message !== null) {
                $errors[] = ['field_uuid' => $field->uuid, 'message' => $message];
                $refused[$field->uuid] = true;
            }
        }

        if ($current === null) {
            return $errors;
        }

        // Asked once, and only when a field below makes it matter.
        $hasEntries = null;
        $entriesExist = function () use ($current, &$hasEntries): bool {
            return $hasEntries ??= $this->entries->hasEntries($current);
        };

        foreach ($desired->fields as $field) {
            $old = $saved[$field->uuid] ?? null;

            if ($old === null || isset($refused[$field->uuid])) {
                continue;
            }

            $message = $this->entryProblem($field, $old, $current, $entriesExist);

            if ($message !== null) {
                $errors[] = ['field_uuid' => $field->uuid, 'message' => $message];
            }
        }

        return [...$errors, ...$this->namingFieldProblems($current, $desired, $saved)];
    }

    /**
     * The first thing wrong with where a relation field points, or null.
     *
     * @param  array{models: array<int, SchemaModel>, fields: array<int, array<string, array{label: string, type: string, settings: array<array-key, mixed>|null}>>}  $targets
     */
    private function linkProblem(FieldSpec $field, ?SchemaField $old, ?SchemaModel $current, ModelSchema $desired, array $targets, ?User $by): ?string
    {
        $name = $this->name($field);
        $link = RelationType::link($field->settings);

        if ($link['model'] === null) {
            return "\"{$name}\": choose the model it links to.";
        }

        $gone = "\"{$name}\" links to a model or field this site doesn't have. Choose them again.";
        $self = $current !== null && $link['model'] === $current->id;
        $target = $self ? $current : ($targets['models'][$link['model']] ?? null);

        if ($target === null) {
            return $gone;
        }

        $title = $self ? $desired->label : $target->label;

        if (! $self && $target->isDeleting()) {
            return "\"{$name}\" links to {$title}, which is being deleted.";
        }

        // When a model links to itself, the fields to choose from are the
        // ones it is being saved with.
        $fields = $self ? $this->specs($desired) : ($targets['fields'][$target->id] ?? []);

        if ($link['label_field'] === null) {
            $namers = array_filter($fields, fn (array $candidate) => RelationType::canLabel($candidate['type'], $candidate['settings']));

            return $namers === []
                ? "{$title} has no field that can name its entries. Add a text field to it first."
                : "\"{$name}\": choose the field that names {$title} entries.";
        }

        $namer = $fields[$link['label_field']] ?? null;

        if ($namer === null) {
            return $gone;
        }

        if (! RelationType::canLabel($namer['type'], $namer['settings'])) {
            return "\"{$name}\": {$namer['label']} can't name entries. Pick a text, number or single choice field.";
        }

        $moved = $old === null || $old->type !== 'relation' || RelationType::link($old->settings)['model'] !== $link['model'];

        if ($by !== null && $moved && ! $this->access->allows($by, Ability::ENTRIES_VIEW, $target->id)) {
            return "\"{$name}\": your role can't view {$title} entries, so it can't link to them.";
        }

        // A demote that fails partway would leave a filterable field holding
        // lists, so filtering goes off in a save of its own first.
        if ($old !== null && $old->type === 'relation' && $old->filterable
            && ! ($old->settings['multiple'] ?? false) && ($field->settings['multiple'] ?? false)) {
            return "Turn off filtering on \"{$name}\" first, then allow several entries.";
        }

        return null;
    }

    /**
     * What a saved field can't do once the model has entries, or null. The
     * entries are looked for only when the field changes in a way that
     * would misread the links already stored.
     *
     * @param  Closure(): bool  $entriesExist
     */
    private function entryProblem(FieldSpec $field, SchemaField $old, SchemaModel $current, Closure $entriesExist): ?string
    {
        $name = $this->name($field);
        $wasRelation = $old->type === 'relation';

        if ($wasRelation !== ($field->type === 'relation')) {
            return $entriesExist()
                ? "\"{$name}\" can't switch to or from a relation once {$current->label} has entries. Add a new field instead."
                : null;
        }

        if (! $wasRelation) {
            return null;
        }

        if (($old->settings['multiple'] ?? false) && ! ($field->settings['multiple'] ?? false)) {
            return $entriesExist()
                ? "\"{$name}\" already holds several entries, so it can't go back to one. Add a new relation field instead."
                : null;
        }

        $was = RelationType::link($old->settings)['model'];

        if ($was !== null && $was !== RelationType::link($field->settings)['model']) {
            $oldTitle = SchemaModel::query()->find($was)->label ?? 'another model';

            return $entriesExist()
                ? "\"{$name}\" already links to {$oldTitle} entries, so it can't link to another model. Add a new relation field instead."
                : null;
        }

        return null;
    }

    /**
     * Saved fields this save removes, or turns into something that can't
     * name entries, while another model's relation field still names its
     * entries by them.
     *
     * @param  array<string, SchemaField>  $saved
     * @return list<array{field_uuid: ?string, message: string}>
     */
    private function namingFieldProblems(SchemaModel $current, ModelSchema $desired, array $saved): array
    {
        $problems = [];

        foreach ($saved as $row) {
            $now = $desired->field($row->id);

            if ($now === null) {
                $problems[$row->id] = "can't be removed.";
            } elseif (RelationType::canLabel($row->type, $row->settings) && ! RelationType::canLabel($now->type, $now->settings)) {
                $problems[$row->id] = "can't change to a type that can't name entries.";
            }
        }

        if ($problems === []) {
            return [];
        }

        $others = SchemaField::query()->where('type', 'relation')->where('model_id', '!=', $current->id)->get();
        $errors = [];

        foreach ($problems as $uuid => $problem) {
            $user = $others->first(fn (SchemaField $other) => RelationType::link($other->settings)['label_field'] === $uuid);

            if ($user !== null) {
                $owner = SchemaModel::query()->find($user->model_id)->label ?? 'Another model';

                $errors[] = [
                    'field_uuid' => $uuid,
                    'message' => "\"{$saved[$uuid]->label}\" {$problem} {$owner} names its links by it. Change that first.",
                ];
            }
        }

        return $errors;
    }

    /**
     * The models these relation fields link to, and their fields, in
     * two queries however many fields there are. The model being saved
     * isn't looked up, since its fields come from the schema being saved.
     *
     * @param  list<FieldSpec>  $relations
     * @return array{models: array<int, SchemaModel>, fields: array<int, array<string, array{label: string, type: string, settings: array<array-key, mixed>|null}>>}
     */
    private function targets(?SchemaModel $current, array $relations): array
    {
        $ids = [];

        foreach ($relations as $field) {
            $id = RelationType::link($field->settings)['model'];

            if ($id !== null && $id !== $current?->id) {
                $ids[$id] = $id;
            }
        }

        $models = [];
        $fields = [];

        if ($ids !== []) {
            foreach (SchemaModel::query()->whereIn('id', $ids)->get() as $model) {
                $models[$model->id] = $model;
            }

            foreach (SchemaField::query()->whereIn('model_id', $ids)->get() as $row) {
                $fields[$row->model_id][$row->id] = ['label' => $row->label, 'type' => $row->type, 'settings' => $row->settings];
            }
        }

        return ['models' => $models, 'fields' => $fields];
    }

    /**
     * @return array<string, array{label: string, type: string, settings: array<array-key, mixed>|null}>
     */
    private function specs(ModelSchema $schema): array
    {
        $specs = [];

        foreach ($schema->fields as $field) {
            $specs[$field->uuid] = ['label' => $field->label, 'type' => $field->type, 'settings' => $field->settings];
        }

        return $specs;
    }

    private function name(FieldSpec $field): string
    {
        return trim($field->label) === '' ? $field->key : $field->label;
    }
}
