<?php

namespace App\Schema;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\FieldTypeRegistry;
use App\Schema\Operations\AddField;
use App\Schema\Operations\DeleteField;
use App\Schema\Operations\Demote;
use App\Schema\Operations\MetadataOnly;
use App\Schema\Operations\Operation;
use App\Schema\Operations\Promote;
use App\Schema\Operations\RenameField;
use App\Schema\Operations\RetypeField;

/**
 * Compares a model's stored schema with the one the builder wants and
 * lists the operations that get from one to the other.
 *
 * Pure: it reads no database, so the caller passes in what it needs to
 * know about StarDust (field ids, busy states, keys still held). Fields
 * match by UUID, so a new key is a rename and never a delete plus an add.
 *
 * Everything StarDust would refuse is refused here first, in words the
 * builder can show, so a save never gets halfway before failing on a rule
 * that was knowable up front.
 */
final class SchemaDiff
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public const SLUG_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    /** StarDust's name column is VARCHAR(128). */
    public const MAX_KEY_LENGTH = 128;

    private const NUMBER_TYPES = ['int', 'numeric'];

    private const BUSY = [
        'indexing' => 'is still being indexed',
        'renaming' => 'is still being renamed',
        'retyping' => 'is still being converted to its new type',
        'deleting' => 'is still being deleted',
        'waiting' => 'has changes from an earlier save that have not been applied yet',
    ];

    public function __construct(private readonly FieldTypeRegistry $types) {}

    /**
     * @param  array<string, int>  $stardustIds  uuid => StarDust field id, for the stored fields.
     * @param  array<string, string>  $states  uuid => field state; anything but "ready" is busy.
     * @param  list<string>  $heldKeys  Keys StarDust still holds for a field being deleted, or
     *                                  as the old name of a field being renamed.
     */
    public function diff(ModelSchema $current, ModelSchema $desired, array $stardustIds = [], array $states = [], array $heldKeys = []): DiffResult
    {
        $errors = [];
        $fail = function (?string $uuid, string $message) use (&$errors): void {
            $errors[] = ['field_uuid' => $uuid, 'message' => $message];
        };

        $this->checkModel($desired, $fail);
        $resolved = $this->checkFields($desired, $fail);

        $currentByUuid = [];
        $currentPosition = [];
        $keyOwner = [];
        foreach ($current->fields as $position => $field) {
            $currentByUuid[$field->uuid] = $field;
            $currentPosition[$field->uuid] = $position;
            $keyOwner[$field->key] = $field->uuid;
        }

        $desiredKeys = [];
        foreach ($desired->fields as $field) {
            $desiredKeys[$field->uuid] = $field->key;
        }

        $busy = fn (string $uuid) => self::BUSY[$states[$uuid] ?? 'ready'] ?? null;
        $ops = ['delete' => [], 'rename' => [], 'retype' => [], 'filter' => [], 'add' => []];
        $fieldChanges = [];

        foreach ($current->fields as $field) {
            if ($desired->field($field->uuid) !== null) {
                continue;
            }

            if (($reason = $busy($field->uuid)) !== null) {
                $fail($field->uuid, "\"{$field->label}\" {$reason}, so it can't be deleted yet.");

                continue;
            }

            $ops['delete'][] = new DeleteField($field->uuid, $stardustIds[$field->uuid] ?? 0, $field->label, $field->key);
        }

        foreach ($desired->fields as $position => $field) {
            if (! isset($resolved[$field->uuid])) {
                continue;
            }

            [$type, $settings] = $resolved[$field->uuid];
            $declared = $type->declaredType($settings);
            $old = $currentByUuid[$field->uuid] ?? null;

            $keyTaken = function () use ($field, $heldKeys, $keyOwner, $desiredKeys, $fail): bool {
                if (in_array($field->key, $heldKeys, true)) {
                    $fail($field->uuid, "The key {$field->key} is still being cleared from a deleted or renamed field. Use it again once that finishes.");

                    return true;
                }

                $owner = $keyOwner[$field->key] ?? null;
                if ($owner !== null && $owner !== $field->uuid && ($desiredKeys[$owner] ?? null) !== $field->key) {
                    $fail($field->uuid, "The key {$field->key} still belongs to another field in the saved model. Save that field's change first, then reuse the key.");

                    return true;
                }

                return false;
            };

            if ($old === null) {
                if (! $keyTaken()) {
                    $ops['add'][] = new AddField($field->uuid, $this->columns($field, $settings, $position), $declared);
                }

                continue;
            }

            $oldType = $this->types->find($old->type);
            if ($oldType === null) {
                $fail($field->uuid, "\"{$old->label}\" uses the field type {$old->type}, which is no longer installed.");

                continue;
            }

            $oldSettings = $oldType->resolveSettings($old->settings);
            $oldDeclared = $oldType->declaredType($oldSettings);

            $renamed = $old->key !== $field->key;
            $retyped = $oldDeclared !== $declared;
            $refiltered = $old->filterable !== $field->filterable;

            if (($renamed || $retyped || $refiltered) && ($reason = $busy($field->uuid)) !== null) {
                $fail($field->uuid, "\"{$field->label}\" {$reason}. Try this change again once that finishes.");

                continue;
            }

            if ($renamed && ($retyped || $refiltered)) {
                $fail($field->uuid, "Save the new key of \"{$field->label}\" first, then change its type or filtering.");

                continue;
            }

            if ($retyped && $this->isNumberDateSwap($oldDeclared, $declared)) {
                $fail($field->uuid, "\"{$field->label}\" can't switch between a number and a date. Add a new field of the type you want, copy the values over, then delete this one.");

                continue;
            }

            if ($retyped && $refiltered && ! $field->filterable) {
                $fail($field->uuid, "Turn off filtering on \"{$field->label}\" and change its type in two separate saves.");

                continue;
            }

            if ($renamed && $keyTaken()) {
                continue;
            }

            $stardustId = $stardustIds[$field->uuid] ?? 0;

            if ($renamed) {
                $ops['rename'][] = new RenameField($field->uuid, $stardustId, $field->label, $old->key, $field->key);
            }

            if ($retyped) {
                $ops['retype'][] = new RetypeField($field->uuid, $stardustId, $field->label, $oldDeclared, $declared, $field->type, $settings, $old->filterable);
            }

            if ($refiltered) {
                $ops['filter'][] = $field->filterable
                    ? new Promote($field->uuid, $stardustId, $field->label)
                    : new Demote($field->uuid, $stardustId, $field->label);
            }

            $columns = $this->metadataColumns($field, $settings, $position, writeStorageColumns: ! $retyped);
            $was = $this->metadataColumns($old, $oldSettings, $currentPosition[$old->uuid], writeStorageColumns: ! $retyped);
            if ($columns != $was) {
                $fieldChanges[$field->uuid] = ['label' => $field->label, 'columns' => $columns];
            }
        }

        $metadata = $this->metadata($current, $desired, $fieldChanges);

        return new DiffResult(
            array_values(array_filter([
                $metadata,
                ...$ops['delete'],
                ...$ops['rename'],
                ...$ops['retype'],
                ...$ops['filter'],
                ...$ops['add'],
            ], fn (?Operation $operation) => $operation !== null)),
            $errors,
        );
    }

    /**
     * @param  callable(?string, string): void  $fail
     */
    private function checkModel(ModelSchema $model, callable $fail): void
    {
        if (strlen($model->slug) > self::MAX_KEY_LENGTH || preg_match(self::SLUG_PATTERN, $model->slug) !== 1) {
            $fail(null, 'The model slug may only use lower-case letters, digits, dashes and underscores, must start with a letter, and can be at most 128 characters.');
        }

        if (trim($model->label) === '' || mb_strlen($model->label) > 255) {
            $fail(null, 'The model needs a name of at most 255 characters.');
        }

        if (mb_strlen((string) $model->icon) > 64 || mb_strlen((string) $model->group) > 64) {
            $fail(null, 'The model icon and group can be at most 64 characters.');
        }

        $ids = [];
        foreach ($model->layout as $block) {
            foreach ([$block['id'], ...array_column($block['slots'], 'id')] as $id) {
                if (isset($ids[$id])) {
                    $fail(null, "The layout uses the id {$id} twice.");
                }

                $ids[$id] = true;
            }
        }
    }

    /**
     * Checks each field on its own and against its siblings.
     *
     * @param  callable(?string, string): void  $fail
     * @return array<string, array{FieldType, array<string, mixed>}> uuid => its type and
     *                                                               resolved settings, for
     *                                                               the fields that passed
     */
    private function checkFields(ModelSchema $model, callable $fail): array
    {
        $resolved = [];
        $uuids = [];
        $keys = [];
        $slots = $model->slotIds();

        foreach ($model->fields as $field) {
            if (isset($uuids[$field->uuid])) {
                $fail($field->uuid, "\"{$field->label}\" appears twice in the model.");

                continue;
            }
            $uuids[$field->uuid] = true;

            $valid = true;
            $name = trim($field->label) === '' ? $field->key : $field->label;

            if (($keyError = $this->keyError($field->key)) !== null) {
                $fail($field->uuid, "\"{$name}\": {$keyError}");
                $valid = false;
            } elseif (isset($keys[$field->key])) {
                $fail($field->uuid, "\"{$name}\": another field already uses the key {$field->key}.");
                $valid = false;
            }
            $keys[$field->key] = true;

            if (trim($field->label) === '' || mb_strlen($field->label) > 255) {
                $fail($field->uuid, "The field {$field->key} needs a label of at most 255 characters.");
                $valid = false;
            }

            if ($field->layoutSlot !== null && ! in_array($field->layoutSlot, $slots, true)) {
                $fail($field->uuid, "\"{$name}\" is placed in a layout slot that doesn't exist.");
                $valid = false;
            }

            $type = $this->types->find($field->type);
            if ($type === null) {
                $fail($field->uuid, in_array($field->type, FieldTypeRegistry::RESERVED, true)
                    ? "\"{$name}\": {$field->type} fields are not available yet."
                    : "\"{$name}\" has an unknown field type, {$field->type}.");

                continue;
            }

            $settings = $type->resolveSettings($field->settings);
            foreach ($type->settingErrors($settings) as $error) {
                $fail($field->uuid, "\"{$name}\": {$error}");
                $valid = false;
            }

            if ($valid && $field->filterable && ! $type->canFilter($settings)) {
                $fail($field->uuid, "\"{$name}\" can't be filterable: this kind of field is only kept inside each entry, not indexed.");
                $valid = false;
            }

            if ($valid) {
                $resolved[$field->uuid] = [$type, $settings];
            }
        }

        return $resolved;
    }

    private function keyError(string $key): ?string
    {
        return match (true) {
            $key === '' => 'every field needs a key.',
            str_starts_with($key, '_') => 'keys starting with _ are reserved for StarSystem.',
            strlen($key) > self::MAX_KEY_LENGTH => 'a key can be at most 128 characters.',
            preg_match(self::KEY_PATTERN, $key) !== 1 => 'a key may only use lower-case letters, digits and underscores, and must start with a letter.',
            default => null,
        };
    }

    /**
     * StarDust refuses these outright: reading a number as a date needs an
     * epoch convention the engine leaves to the caller.
     */
    private function isNumberDateSwap(string $from, string $to): bool
    {
        return (in_array($from, self::NUMBER_TYPES, true) && $to === 'datetime')
            || ($from === 'datetime' && in_array($to, self::NUMBER_TYPES, true));
    }

    /**
     * The ss_fields columns of a new field.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function columns(FieldSpec $field, array $settings, int $position): array
    {
        return [
            'key' => $field->key,
            'filterable' => $field->filterable,
            ...$this->metadataColumns($field, $settings, $position, writeStorageColumns: true),
        ];
    }

    /**
     * The ss_fields columns a metadata write owns. A retype writes type and
     * settings itself, after StarDust accepts it, so they are left out then.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function metadataColumns(FieldSpec $field, array $settings, int $position, bool $writeStorageColumns): array
    {
        $columns = [
            'label' => $field->label,
            'helper' => $field->helper,
            'required' => $field->required,
            'shown_in_list' => $field->shownInList,
            'position' => $position,
            'layout_slot' => $field->layoutSlot,
        ];

        return $writeStorageColumns ? [...$columns, 'type' => $field->type, 'settings' => $settings] : $columns;
    }

    /**
     * @param  array<string, array{label: string, columns: array<string, mixed>}>  $fieldChanges
     */
    private function metadata(ModelSchema $current, ModelSchema $desired, array $fieldChanges): ?MetadataOnly
    {
        $changes = [];

        if ($current->slug !== $desired->slug) {
            $changes[] = "the model slug to {$desired->slug}";
        }

        if ([$current->label, $current->icon, $current->group] !== [$desired->label, $desired->icon, $desired->group]) {
            $changes[] = 'the model details';
        }

        if ($current->layout != $desired->layout) {
            $changes[] = 'the layout';
        }

        if ($fieldChanges !== []) {
            $labels = array_map(fn ($change) => "\"{$change['label']}\"", array_values($fieldChanges));
            $changes[] = 'the details of '.(count($labels) > 4
                ? implode(', ', array_slice($labels, 0, 4)).' and '.(count($labels) - 4).' more'
                : implode(', ', $labels));
        }

        if ($changes === []) {
            return null;
        }

        return new MetadataOnly(
            previousSlug: $current->slug,
            model: [
                'slug' => $desired->slug,
                'label' => $desired->label,
                'icon' => $desired->icon,
                'group' => $desired->group,
                'layout' => $desired->layout,
            ],
            fields: array_map(fn ($change) => $change['columns'], $fieldChanges),
            changes: $changes,
        );
    }
}
