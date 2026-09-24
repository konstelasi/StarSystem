<?php

namespace App\Schema;

use App\Models\SchemaField;
use Illuminate\Support\Str;
use stdClass;

/**
 * One field as the builder describes it. Immutable and identified by its
 * UUID, never by its key, so a changed key reads as a rename.
 *
 * A field's position is its index in ModelSchema::$fields.
 */
final class FieldSpec
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly array $settings = [],
        public readonly ?string $helper = null,
        public readonly bool $required = false,
        public readonly bool $filterable = false,
        public readonly ?string $layoutSlot = null,
    ) {}

    public static function fromField(SchemaField $field): self
    {
        return new self(
            uuid: $field->id,
            key: $field->key,
            label: $field->label,
            type: $field->type,
            settings: $field->settings ?? [],
            helper: $field->helper,
            required: $field->required,
            filterable: $field->filterable,
            layoutSlot: $field->layout_slot,
        );
    }

    /**
     * Reads one FieldSpecJson. A missing uuid gets a new one, so JSON
     * written by hand imports as new fields rather than failing.
     *
     * @param  array<array-key, mixed>  $json
     *
     * @throws InvalidSchemaException
     */
    public static function fromJson(array $json, string $path = 'field'): self
    {
        $uuid = $json['uuid'] ?? (string) Str::uuid();
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            throw InvalidSchemaException::at("{$path}.uuid", 'must be a UUID');
        }

        foreach (['key', 'label', 'type'] as $name) {
            if (! is_string($json[$name] ?? null)) {
                throw InvalidSchemaException::at("{$path}.{$name}", 'must be a string');
            }
        }

        $settings = $json['settings'] ?? [];
        if ($settings instanceof stdClass) {
            $settings = (array) $settings;
        }
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw InvalidSchemaException::at("{$path}.settings", 'must be an object');
        }

        foreach (['helper', 'layout_slot'] as $name) {
            if (isset($json[$name]) && ! is_string($json[$name])) {
                throw InvalidSchemaException::at("{$path}.{$name}", 'must be a string or null');
            }
        }

        foreach (['required', 'filterable'] as $name) {
            if (isset($json[$name]) && ! is_bool($json[$name])) {
                throw InvalidSchemaException::at("{$path}.{$name}", 'must be true or false');
            }
        }

        /** @var array<string, mixed> $settings */
        return new self(
            uuid: strtolower($uuid),
            key: $json['key'],
            label: $json['label'],
            type: $json['type'],
            settings: $settings,
            helper: isset($json['helper']) && $json['helper'] !== '' ? $json['helper'] : null,
            required: $json['required'] ?? false,
            filterable: $json['filterable'] ?? false,
            layoutSlot: $json['layout_slot'] ?? null,
        );
    }

    /**
     * FieldSpecJson. Empty settings become an object so the JSON is {} and
     * not [].
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        $json = [
            'uuid' => $this->uuid,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
        ];

        if ($this->helper !== null) {
            $json['helper'] = $this->helper;
        }

        return [
            ...$json,
            'required' => $this->required,
            'filterable' => $this->filterable,
            'layout_slot' => $this->layoutSlot,
            'settings' => $this->settings === [] ? new stdClass : $this->settings,
        ];
    }

    /**
     * A copy with some properties changed, by constructor parameter name.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
