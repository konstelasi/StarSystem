<?php

namespace App\Schema;

use App\StarDust\FieldConflictException;
use App\StarDust\NotOwnedException;
use RuntimeException;
use StarDust\Exception\FieldDeletionInProgressException;
use StarDust\Exception\FieldNameConflictException;
use StarDust\Exception\FieldNotFoundException;
use StarDust\Exception\IncompatibleRetypeException;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Exception\ModelNameConflictException;
use StarDust\Exception\ModelNotFoundException;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use Throwable;

/**
 * A schema change StarSystem or StarDust refused, with a message the
 * builder can show as it is.
 */
class SchemaException extends RuntimeException
{
    /**
     * The builder-facing message for anything a schema operation threw.
     * StarDust's own messages name ids and ADRs, which mean nothing to
     * someone building a content model.
     */
    public static function describe(Throwable $e): string
    {
        return match (true) {
            $e instanceof self => $e->getMessage(),
            $e instanceof FieldNameConflictException => self::text('Another field of this model already uses that key, or it is still being cleared from a renamed field.'),
            $e instanceof FieldDeletionInProgressException => self::text('That key belongs to a field that is still being deleted. Use it again once the deletion finishes.'),
            $e instanceof RenameInProgressException => self::text('The field is still being renamed. Try again once the rename finishes.'),
            $e instanceof RetypeInProgressException => self::text('The field is still being converted or indexed. Try again once that finishes.'),
            $e instanceof IncompatibleRetypeException => self::text('A field can\'t switch between a number and a date.'),
            $e instanceof ModelDeletionInProgressException => self::text('The model is being deleted.'),
            $e instanceof ModelNameConflictException => self::text('Another model already uses that slug.'),
            $e instanceof FieldNotFoundException, $e instanceof ModelNotFoundException => self::text('StarDust no longer has this field or model. Reload the builder.'),
            $e instanceof FieldConflictException => self::text('StarDust already has a field named :key with a different type. Choose another key.', ['key' => $e->fieldName]),
            $e instanceof NotOwnedException => self::text('This model does not belong to the current site.'),
            default => self::text('The change could not be applied: :message', ['message' => $e->getMessage()]),
        };
    }

    /**
     * A translatable message. The English text is the key, so a site can
     * add a translation file later without code changes.
     *
     * @param  array<string, string>  $replace
     */
    public static function text(string $message, array $replace = []): string
    {
        $translated = __($message, $replace);

        return is_string($translated) ? $translated : $message;
    }

    /**
     * Whether the failure is one of the refusals above, as opposed to a
     * bug or an outage worth reporting.
     */
    public static function isExpected(Throwable $e): bool
    {
        return $e instanceof self
            || $e instanceof FieldConflictException
            || $e instanceof NotOwnedException
            || str_starts_with($e::class, 'StarDust\\Exception\\');
    }
}
