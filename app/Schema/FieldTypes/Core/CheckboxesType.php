<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;
use App\Schema\OptionsSource;
use Illuminate\Validation\Rule;

/**
 * Any number of choices, as a list. A StarDust slot holds one value, so a
 * list is JSON-only.
 */
class CheckboxesType extends FieldType
{
    public function key(): string
    {
        return 'checkboxes';
    }

    public function label(): string
    {
        return 'Checkboxes';
    }

    public function icon(): string
    {
        return 'list-checks';
    }

    public function category(): string
    {
        return 'choice';
    }

    public function settings(): array
    {
        return [Setting::options()];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => false];
    }

    public function rules(array $settings, bool $required): array
    {
        $values = OptionsSource::staticValues($settings['options'] ?? null);

        return [
            '' => [$required ? 'required' : 'nullable', 'array', 'list'],
            '.*' => $values === null ? ['string'] : ['string', Rule::in($values)],
        ];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        return self::normalizeList($value);
    }

    /**
     * A list of distinct strings, or null when nothing was chosen.
     *
     * @return list<string>|null
     */
    public static function normalizeList(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $items = array_map(fn ($item) => is_scalar($item) ? (string) $item : '', (array) $value);

        return array_values(array_unique($items));
    }
}
