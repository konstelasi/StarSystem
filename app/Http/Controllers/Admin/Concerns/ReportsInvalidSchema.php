<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Schema\SaveResult;
use Illuminate\Validation\ValidationException;

/**
 * The Form Requests catch the common problems (a bad, taken or held slug)
 * before SchemaManager runs at all. What reaches here is a race with
 * another request, or a problem inside an imported file — still reported
 * as a form error rather than a 500 or a false "saved".
 */
trait ReportsInvalidSchema
{
    /**
     * @throws ValidationException
     */
    protected function failIfInvalid(SaveResult $result, string $fieldsKey): void
    {
        if ($result->status !== SaveResult::INVALID) {
            return;
        }

        $messages = [];
        foreach ($result->preview['errors'] as $error) {
            $messages[$error['field_uuid'] === null ? 'slug' : $fieldsKey][] = $error['message'];
        }

        throw ValidationException::withMessages($messages);
    }
}
