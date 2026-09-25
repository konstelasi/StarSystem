<?php

namespace App\Files\Http\Rules;

use App\Files\FileRejected;
use App\Files\FileStore;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Runs FileStore's own checks (upload errors, size, detected type) during
 * validation, so every rejected file of a multi-file upload is reported
 * with the same plain message FileStore would give, before any is stored.
 */
class AllowedUpload implements ValidationRule
{
    public function __construct(private readonly FileStore $store) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('No file was received.');

            return;
        }

        try {
            $this->store->inspect($value);
        } catch (FileRejected $e) {
            $fail($e->getMessage());
        }
    }
}
