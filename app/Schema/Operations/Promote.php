<?php

namespace App\Schema\Operations;

/**
 * Turns filtering on. StarDust backfills an index from the stored values
 * on the tick; until then the field shows as indexing.
 */
final class Promote extends FieldOperation
{
    public function kind(): string
    {
        return 'promote';
    }

    public function summary(): string
    {
        return "Make \"{$this->label}\" filterable. Filters work once indexing finishes in the background.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['uuid'], $payload['stardust_field_id'], $payload['label']);
    }
}
