<?php

namespace App\Schema\Operations;

/**
 * An operation on a field StarDust already has, which it names by id.
 */
abstract class FieldOperation extends Operation
{
    public function __construct(
        public readonly string $uuid,
        public readonly int $stardustFieldId,
        public readonly string $label,
    ) {}

    public function fieldUuid(): string
    {
        return $this->uuid;
    }

    public function payload(): array
    {
        return ['uuid' => $this->uuid, 'stardust_field_id' => $this->stardustFieldId, 'label' => $this->label];
    }
}
