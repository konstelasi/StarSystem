<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * One line of text. Filterable, so it is capped at the 4096 characters a
 * StarDust string slot holds; longer text belongs in a text area.
 */
class TextType extends FieldType
{
    public const MAX_LENGTH = 4096;

    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Text';
    }

    public function icon(): string
    {
        return 'type';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function settings(): array
    {
        return [
            Setting::text('placeholder', 'Placeholder'),
            Setting::text('default', 'Default value'),
            Setting::number('max_length', 'Maximum length', null, 'Leave empty to allow up to 4096 characters.', ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LENGTH]),
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => true];
    }

    protected function valueRules(array $settings): array
    {
        $max = is_numeric($settings['max_length'] ?? null) ? (int) $settings['max_length'] : self::MAX_LENGTH;

        return ['string', 'max:'.min($max, self::MAX_LENGTH)];
    }
}
