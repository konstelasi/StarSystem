<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A link to an entry of another model, or to several. The value is the
 * linked entry's id, or a list of ids with "Allow several entries" on.
 *
 * What a link may point at is checked when the model is saved
 * (RelationCheck) and which ids an entry may hold when it is saved
 * (EntryLinks), because both need the database. This class holds the shape
 * of the settings and values, and the helpers the others share.
 */
class RelationType extends FieldType
{
    public const MAX_LINKS = 50;

    /**
     * Field types whose value can name an entry in a list or a picker. A
     * select only counts with a single choice. Mirrored as LABEL_TYPES in
     * resources/js/lib/modelSchema.ts.
     */
    public const LABEL_TYPES = ['text', 'textarea', 'email', 'url', 'hidden', 'number', 'radio', 'select'];

    public function key(): string
    {
        return 'relation';
    }

    public function label(): string
    {
        return 'Relation';
    }

    public function icon(): string
    {
        return 'link-2';
    }

    public function category(): string
    {
        return 'choice';
    }

    public function settings(): array
    {
        return [
            Setting::modelLink('link', 'Links to', 'The model to pick entries from, and the field that names them.'),
            Setting::boolean('multiple', 'Allow several entries'),
        ];
    }

    /**
     * A whole number either way, so turning "several" on or off never
     * retypes the field. StarDust only coerces filterable fields, and a
     * list can't be filtered on.
     */
    public function storage(array $settings): array
    {
        return ['declaredType' => 'int', 'canFilter' => ! ($settings['multiple'] ?? false)];
    }

    public function rules(array $settings, bool $required): array
    {
        if (! ($settings['multiple'] ?? false)) {
            return parent::rules($settings, $required);
        }

        return [
            '' => [$required ? 'required' : 'nullable', 'array', 'list', 'max:'.self::MAX_LINKS],
            '.*' => $this->valueRules($settings),
        ];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ids = self::ids($value);

        if ($ids === []) {
            return null;
        }

        return ($settings['multiple'] ?? false) ? $ids : $ids[0];
    }

    /**
     * The builder's select sends the model as a string, so it is stored as
     * the whole number it is.
     */
    public function resolveSettings(array $given): array
    {
        $resolved = parent::resolveSettings($given);

        if (is_array($resolved['link']) && is_string($resolved['link']['model'] ?? null) && ctype_digit($resolved['link']['model'])) {
            $resolved['link']['model'] = (int) $resolved['link']['model'];
        }

        return $resolved;
    }

    /**
     * The linked model's id and the id of the field that names its entries,
     * from raw or resolved settings. Anything unusable reads as null.
     *
     * @param  array<array-key, mixed>|null  $settings
     * @return array{model: ?int, label_field: ?string}
     */
    public static function link(?array $settings): array
    {
        $link = is_array($settings['link'] ?? null) ? $settings['link'] : [];
        $model = $link['model'] ?? null;
        $labelField = $link['label_field'] ?? null;

        return [
            'model' => self::wholeNumber($model),
            'label_field' => is_string($labelField) && $labelField !== '' ? $labelField : null,
        ];
    }

    /**
     * The entry ids in a stored or submitted value. A lone id reads as a
     * list of one, so a field switched to several still reads what it held
     * before. Repeats and anything that isn't an id are dropped.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            $id = self::wholeNumber($item);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a field of this type and settings can name entries.
     *
     * @param  array<array-key, mixed>|null  $settings
     */
    public static function canLabel(string $type, ?array $settings): bool
    {
        return in_array($type, self::LABEL_TYPES, true)
            && ! ($type === 'select' && ($settings['multiple'] ?? false));
    }

    protected function valueRules(array $settings): array
    {
        return ['integer', 'min:1'];
    }

    private static function wholeNumber(mixed $value): ?int
    {
        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => 0,
        };

        return $id > 0 ? $id : null;
    }
}
