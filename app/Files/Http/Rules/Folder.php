<?php

namespace App\Files\Http\Rules;

use App\Files\FileRejected;
use App\Files\FolderPath;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A virtual folder path that FolderPath accepts ("" is the root).
 */
class Folder implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! is_string($value)) {
            $fail('The folder must be a path such as "photos/2026".');

            return;
        }

        try {
            FolderPath::normalize($value);
        } catch (FileRejected $e) {
            $fail($e->getMessage());
        }
    }
}
