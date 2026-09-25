<?php

namespace App\Concerns;

use App\Schema\SchemaDiff;
use App\Schema\SchemaManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait ModelIdentityRules
{
    /**
     * @return array<int, ValidationRule|array<mixed>|string|Closure>
     */
    protected function slugRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:'.SchemaDiff::MAX_KEY_LENGTH,
            'regex:'.SchemaDiff::SLUG_PATTERN,
            function (string $attribute, mixed $value, Closure $fail) {
                if (($problem = app(SchemaManager::class)->slugProblem((string) $value)) !== null) {
                    $fail($problem);
                }
            },
        ];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function labelRules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:255'];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function groupRules(): array
    {
        return ['nullable', 'string', 'max:64'];
    }
}
