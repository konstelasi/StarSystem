<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A slider between a minimum and a maximum.
 */
class RangeType extends FieldType
{
    public function key(): string
    {
        return 'range';
    }

    public function label(): string
    {
        return 'Slider';
    }

    public function icon(): string
    {
        return 'sliders-horizontal';
    }

    public function category(): string
    {
        return 'number';
    }

    public function settings(): array
    {
        return [
            Setting::number('min', 'Minimum', 0, rules: ['required', 'numeric']),
            Setting::number('max', 'Maximum', 100, rules: ['required', 'numeric']),
            Setting::number('step', 'Step', 1, rules: ['required', 'numeric']),
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'numeric', 'canFilter' => true];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? $value + 0 : $value;
    }

    protected function valueRules(array $settings): array
    {
        $min = is_numeric($settings['min'] ?? null) ? $settings['min'] : 0;
        $max = is_numeric($settings['max'] ?? null) ? $settings['max'] : 100;

        return ['numeric', "between:{$min},{$max}"];
    }

    protected function crossCheck(array $settings): array
    {
        return $this->rangeErrors($settings, strict: true);
    }
}
