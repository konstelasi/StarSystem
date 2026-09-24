<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A number. "Whole numbers only" stores it as an int, otherwise as a
 * decimal, so switching that setting is a StarDust retype.
 */
class NumberType extends FieldType
{
    public function key(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'Number';
    }

    public function icon(): string
    {
        return 'hash';
    }

    public function category(): string
    {
        return 'number';
    }

    public function settings(): array
    {
        return [
            Setting::boolean('whole', 'Whole numbers only'),
            Setting::number('min', 'Minimum'),
            Setting::number('max', 'Maximum'),
            Setting::number('step', 'Step', null, 'How much the arrows add or take away.'),
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => ($settings['whole'] ?? false) ? 'int' : 'numeric', 'canFilter' => true];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return $value;
        }

        return ($settings['whole'] ?? false) ? (int) $value : $value + 0;
    }

    protected function valueRules(array $settings): array
    {
        $rules = [($settings['whole'] ?? false) ? 'integer' : 'numeric'];

        if (is_numeric($settings['min'] ?? null)) {
            $rules[] = 'min:'.$settings['min'];
        }

        if (is_numeric($settings['max'] ?? null)) {
            $rules[] = 'max:'.$settings['max'];
        }

        return $rules;
    }

    protected function crossCheck(array $settings): array
    {
        return $this->rangeErrors($settings, strict: false);
    }
}
