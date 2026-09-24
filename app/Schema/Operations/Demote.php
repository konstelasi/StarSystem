<?php

namespace App\Schema\Operations;

/**
 * Turns filtering off, at once. The values stay in every entry.
 */
final class Demote extends FieldOperation
{
    public function kind(): string
    {
        return 'demote';
    }

    public function summary(): string
    {
        return "Stop filtering on \"{$this->label}\". Its values are kept.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['uuid'], $payload['stardust_field_id'], $payload['label']);
    }
}
