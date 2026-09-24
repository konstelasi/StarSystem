<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;

/**
 * Formatted text, stored as HTML. JSON-only.
 */
class RichTextType extends FieldType
{
    public function key(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Rich text';
    }

    public function icon(): string
    {
        return 'pilcrow';
    }

    public function category(): string
    {
        return 'rich';
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'string', 'canFilter' => false];
    }
}
