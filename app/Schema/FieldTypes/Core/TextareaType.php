<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * Plain text over several lines. JSON-only: long text makes a poor filter
 * and can outgrow a StarDust string slot.
 */
class TextareaType extends FieldType
{
    public function key(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'Text area';
    }

    public function icon(): string
    {
        return 'align-left';
    }

    public function category(): string
    {
        return 'basic';
    }

    public function settings(): array
    {
        return [Setting::number('rows', 'Rows', 4, rules: ['required', 'integer', 'min:1', 'max:50'])];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => false];
    }
}
