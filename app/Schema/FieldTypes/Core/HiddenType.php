<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A value the entry form carries but doesn't show.
 */
class HiddenType extends FieldType
{
    public function key(): string
    {
        return 'hidden';
    }

    public function label(): string
    {
        return 'Hidden';
    }

    public function icon(): string
    {
        return 'eye-off';
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

    protected function valueRules(array $settings): array
    {
        return ['string', 'max:'.TextType::MAX_LENGTH];
    }
}
