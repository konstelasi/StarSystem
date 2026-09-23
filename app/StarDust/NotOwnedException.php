<?php

namespace App\StarDust;

use RuntimeException;

/**
 * A StarDust model or field that the current site does not own.
 *
 * Some StarDust calls take no tenant id (SchemaBuilder::defineField() takes
 * only a model id), so StarSystem checks ownership itself before every
 * schema call. Seeing this means a caller passed an id it should never
 * have had.
 */
class NotOwnedException extends RuntimeException
{
    public static function model(int $modelId): self
    {
        return new self("StarDust model {$modelId} does not belong to the current site.");
    }

    public static function field(int $modelId, int $fieldId): self
    {
        return new self("StarDust field {$fieldId} is not a live field of model {$modelId}.");
    }
}
