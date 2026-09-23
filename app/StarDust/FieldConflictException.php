<?php

namespace App\StarDust;

use RuntimeException;
use StarDust\Schema\FieldDescription;

/**
 * defineField() found a field with the requested name but a different type
 * or filterability.
 *
 * StarDust's defineField() is get-or-create by name and silently ignores
 * the type and filterable flag of an existing field, so without this check
 * a save could "add" a number field and get back an old string one.
 */
class FieldConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $fieldName,
        public readonly FieldDescription $existing,
        string $declaredType,
        bool $filterable,
    ) {
        parent::__construct(sprintf(
            "StarDust already has a field '%s' as %s%s; asked for %s%s.",
            $fieldName,
            $existing->declaredType,
            $existing->isFilterable ? ' (filterable)' : '',
            $declaredType,
            $filterable ? ' (filterable)' : '',
        ));
    }
}
