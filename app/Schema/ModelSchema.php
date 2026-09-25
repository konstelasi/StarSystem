<?php

namespace App\Schema;

use App\Models\SchemaModel;
use Illuminate\Support\Str;
use JsonException;

/**
 * A whole model as the builder edits it: the model's own metadata, its
 * layout and its fields in order.
 *
 * toJson() and fromJson() are the export format, and the builder's JSON
 * tab and "copy model to site" use the same format:
 *
 *   {version: 1, model: {slug, label, icon?, group?}, layout: [...], fields: [...]}
 *
 * Layout is presentation only: blocks of kind section, tabs or columns,
 * each with slots a field can sit in (FieldSpec::$layoutSlot).
 */
final class ModelSchema
{
    public const VERSION = 1;

    public const LAYOUT_KINDS = ['section', 'tabs', 'columns'];

    /**
     * @param  list<array{id: string, kind: string, label?: string, slots: list<array{id: string, label?: string}>}>  $layout
     * @param  list<FieldSpec>  $fields  In display order.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly ?string $icon = null,
        public readonly ?string $group = null,
        public readonly array $layout = [],
        public readonly array $fields = [],
    ) {}

    public static function fromModel(SchemaModel $model): self
    {
        $fields = [];
        foreach ($model->fields()->get() as $field) {
            $fields[] = FieldSpec::fromField($field);
        }

        return new self(
            slug: $model->slug,
            label: $model->label,
            icon: $model->icon,
            group: $model->group,
            layout: self::readLayout($model->layout ?? [], 'layout'),
            fields: $fields,
        );
    }

    /**
     * @param  array<array-key, mixed>|string  $json
     *
     * @throws InvalidSchemaException
     */
    public static function fromJson(array|string $json): self
    {
        if (is_string($json)) {
            try {
                $json = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidSchemaException('The schema is not valid JSON: '.$e->getMessage(), previous: $e);
            }

            if (! is_array($json)) {
                throw InvalidSchemaException::at('(root)', 'must be an object');
            }
        }

        if (($json['version'] ?? null) !== self::VERSION) {
            throw InvalidSchemaException::at('version', 'must be '.self::VERSION);
        }

        $model = $json['model'] ?? null;
        if (! is_array($model) || ! is_string($model['slug'] ?? null) || ! is_string($model['label'] ?? null)) {
            throw InvalidSchemaException::at('model', 'needs a slug and a label');
        }

        foreach (['icon', 'group'] as $name) {
            if (isset($model[$name]) && ! is_string($model[$name])) {
                throw InvalidSchemaException::at("model.{$name}", 'must be a string');
            }
        }

        $fields = $json['fields'] ?? [];
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw InvalidSchemaException::at('fields', 'must be a list');
        }

        $specs = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                throw InvalidSchemaException::at("fields[{$i}]", 'must be an object');
            }

            $specs[] = FieldSpec::fromJson($field, "fields[{$i}]");
        }

        return new self(
            slug: $model['slug'],
            label: $model['label'],
            icon: isset($model['icon']) && $model['icon'] !== '' ? $model['icon'] : null,
            group: isset($model['group']) && $model['group'] !== '' ? $model['group'] : null,
            layout: self::readLayout($json['layout'] ?? [], 'layout'),
            fields: $specs,
        );
    }

    /**
     * ModelSchemaJson.
     *
     * @return array{version: int, model: array<string, string>, layout: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    public function toJson(): array
    {
        return [
            'version' => self::VERSION,
            'model' => array_filter([
                'slug' => $this->slug,
                'label' => $this->label,
                'icon' => $this->icon,
                'group' => $this->group,
            ], fn ($value) => $value !== null),
            'layout' => $this->layout,
            'fields' => array_map(fn (FieldSpec $field) => $field->toJson(), $this->fields),
        ];
    }

    public function field(string $uuid): ?FieldSpec
    {
        foreach ($this->fields as $field) {
            if ($field->uuid === $uuid) {
                return $field;
            }
        }

        return null;
    }

    public function fieldByKey(string $key): ?FieldSpec
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function slotIds(): array
    {
        $ids = [];
        foreach ($this->layout as $block) {
            foreach ($block['slots'] as $slot) {
                $ids[] = $slot['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  list<FieldSpec>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self($this->slug, $this->label, $this->icon, $this->group, $this->layout, $fields);
    }

    public function withSlug(string $slug): self
    {
        return new self($slug, $this->label, $this->icon, $this->group, $this->layout, $this->fields);
    }

    public function withLabel(string $label): self
    {
        return new self($this->slug, $label, $this->icon, $this->group, $this->layout, $this->fields);
    }

    /**
     * The same schema with new field UUIDs. Field ids are global, so a
     * schema copied to another model or site must not reuse them.
     */
    public function withFreshUuids(): self
    {
        return $this->withFields(array_map(
            fn (FieldSpec $field) => $field->with(['uuid' => (string) Str::uuid()]),
            $this->fields,
        ));
    }

    /**
     * @return list<array{id: string, kind: string, label?: string, slots: list<array{id: string, label?: string}>}>
     *
     * @throws InvalidSchemaException
     */
    private static function readLayout(mixed $layout, string $path): array
    {
        if (! is_array($layout) || ! array_is_list($layout)) {
            throw InvalidSchemaException::at($path, 'must be a list');
        }

        $blocks = [];
        foreach ($layout as $i => $block) {
            $at = "{$path}[{$i}]";

            if (! is_array($block) || ! is_string($block['id'] ?? null) || $block['id'] === '') {
                throw InvalidSchemaException::at($at, 'needs an id');
            }

            if (! in_array($block['kind'] ?? null, self::LAYOUT_KINDS, true)) {
                throw InvalidSchemaException::at("{$at}.kind", 'must be one of '.implode(', ', self::LAYOUT_KINDS));
            }

            if (! is_array($block['slots'] ?? null) || ! array_is_list($block['slots'])) {
                throw InvalidSchemaException::at("{$at}.slots", 'must be a list');
            }

            $slots = [];
            foreach ($block['slots'] as $j => $slot) {
                if (! is_array($slot) || ! is_string($slot['id'] ?? null) || $slot['id'] === '') {
                    throw InvalidSchemaException::at("{$at}.slots[{$j}]", 'needs an id');
                }

                $read = ['id' => $slot['id']];
                if (($label = self::label($slot, "{$at}.slots[{$j}]")) !== null) {
                    $read['label'] = $label;
                }
                $slots[] = $read;
            }

            $read = ['id' => $block['id'], 'kind' => $block['kind']];
            if (($label = self::label($block, $at)) !== null) {
                $read['label'] = $label;
            }
            $read['slots'] = $slots;
            $blocks[] = $read;
        }

        return $blocks;
    }

    /**
     * @param  array<array-key, mixed>  $from
     *
     * @throws InvalidSchemaException
     */
    private static function label(array $from, string $path): ?string
    {
        if (! isset($from['label'])) {
            return null;
        }

        if (! is_string($from['label'])) {
            throw InvalidSchemaException::at("{$path}.label", 'must be a string');
        }

        return $from['label'];
    }
}
