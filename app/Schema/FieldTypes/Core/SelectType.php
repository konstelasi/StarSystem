<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;
use App\Schema\OptionsSource;
use Illuminate\Validation\Rule;

/**
 * A dropdown. A single choice is a filterable string; several choices are
 * a list, which StarDust can only keep in the JSON payload.
 */
class SelectType extends FieldType
{
    public function key(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return 'Dropdown';
    }

    public function icon(): string
    {
        return 'list';
    }

    public function category(): string
    {
        return 'choice';
    }

    public function settings(): array
    {
        return [
            Setting::options(),
            Setting::boolean('multiple', 'Allow several choices', false, 'A field with several choices can\'t be filtered on.'),
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => ! ($settings['multiple'] ?? false)];
    }

    public function rules(array $settings, bool $required): array
    {
        if (! ($settings['multiple'] ?? false)) {
            return parent::rules($settings, $required);
        }

        return [
            '' => [$required ? 'required' : 'nullable', 'array', 'list'],
            '.*' => $this->valueRules($settings),
        ];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        if (! ($settings['multiple'] ?? false)) {
            return parent::normalize($value, $settings);
        }

        return CheckboxesType::normalizeList($value);
    }

    protected function valueRules(array $settings): array
    {
        $values = OptionsSource::staticValues($settings['options'] ?? null);

        return $values === null ? ['string'] : ['string', Rule::in($values)];
    }
}
