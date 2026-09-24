<?php

namespace App\Schema;

use InvalidArgumentException;

/**
 * Schema JSON that isn't shaped like the export format: a missing key, a
 * string where a list belongs. Whether the content makes sense (known
 * types, unique keys) is the schema diff's job, which reports it per field.
 */
class InvalidSchemaException extends InvalidArgumentException
{
    public static function at(string $path, string $problem): self
    {
        return new self("Invalid schema JSON at {$path}: {$problem}");
    }
}
