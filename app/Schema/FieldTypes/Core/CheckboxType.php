<?php

namespace App\Schema\FieldTypes\Core;

use App\Schema\FieldTypes\FieldType;
use App\Schema\FieldTypes\Setting;

/**
 * A yes/no box. StarDust has no boolean slot, and its int coercion refuses
 * PHP booleans, so the value is stored as the int 1 or 0.
 */
class CheckboxType extends FieldType
{
    public function key(): string
    {
        return 'checkbox';
    }

    public function label(): string
    {
        return 'Checkbox';
    }

    public function icon(): string
    {
        return 'square-check';
    }

    public function category(): string
    {
        return 'choice';
    }

    public function settings(): array
    {
        return [Setting::boolean('default', 'Checked by default')];
    }

    public function storage(array $settings): array
    {
        return ['declaredType' => 'int', 'canFilter' => true];
    }

    /**
     * A required checkbox must be ticked, as with HTML's required.
     */
    public function rules(array $settings, bool $required): array
    {
        return ['' => $required ? ['required', 'accepted'] : ['nullable', 'boolean']];
    }

    public function normalize(mixed $value, array $settings = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
    }
}
