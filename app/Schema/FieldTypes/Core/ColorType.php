<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A #rrggbb colour, stored lower-case so equal colours filter as equal.
 */
class ColorType extends FieldType
{
    public function key(): string
    {
        return 'color';
    }

    public function label(): string
    {
        return 'Colour';
    }

    public function icon(): string
    {
        return 'palette';
    }

    public function category(): string
    {
        return 'advanced';
    }

    public function settings(): array
    {
        return [Setting::text('default', 'Default value')];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => true];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        $value = parent::normalize($value, $settings);

        return is_string($value) ? strtolower($value) : $value;
    }

    protected function valueRules(array $settings): array
    {
        return ['string', 'regex:/^#[0-9a-fA-F]{6}$/'];
    }
}
