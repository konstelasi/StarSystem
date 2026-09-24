<?php

namespace App\Files;

use RuntimeException;

/**
 * An upload or change FileStore refused. The message is written for the
 * person uploading, so the admin shows it as is.
 */
class FileRejected extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
