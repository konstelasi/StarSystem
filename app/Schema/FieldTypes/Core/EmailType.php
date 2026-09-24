<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * An email address, stored lower-case so a filter finds it however it
 * was typed.
 */
class EmailType extends FieldType
{
    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function icon(): string
    {
        return 'mail';
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
        ];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => true];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        $value = parent::normalize($value, $settings);

        return is_string($value) ? strtolower(trim($value)) : $value;
    }

    protected function valueRules(array $settings): array
    {
        return ['string', 'email', 'max:254'];
    }
}
