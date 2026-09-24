<?php

namespace App\Schema\Operations;

/**
 * Removes a field and, in the background, its value from every entry.
 * There is no undo, and the key can't be reused until the purge finishes.
 */
final class DeleteField extends FieldOperation
{
    public function __construct(
        string $uuid,
        int $stardustFieldId,
        string $label,
        public readonly string $key,
    ) {
        parent::__construct($uuid, $stardustFieldId, $label);
    }

    public function kind(): string
    {
        return 'delete';
    }

    public function summary(): string
    {
        return "Delete \"{$this->label}\" and its value in every entry. This can't be undone.";
    }

    public function affectsExistingData(): bool
    {
        return true;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function payload(): array
    {
        return [...parent::payload(), 'key' => $this->key];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['uuid'], $payload['stardust_field_id'], $payload['label'], $payload['key']);
    }
}
