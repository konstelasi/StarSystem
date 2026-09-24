<?php

namespace App\Schema\Operations;

/**
 * A new key for an existing field. StarDust keys stored entries by field
 * name, so the tick rewrites every entry; reads use the new key at once.
 */
final class RenameField extends FieldOperation
{
    public function __construct(
        string $uuid,
        int $stardustFieldId,
        string $label,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct($uuid, $stardustFieldId, $label);
    }

    public function kind(): string
    {
        return 'rename';
    }

    public function summary(): string
    {
        return "Rename the key of \"{$this->label}\" from {$this->from} to {$this->to}. Existing entries are rewritten in the background.";
    }

    public function affectsExistingData(): bool
    {
        return true;
    }

    public function payload(): array
    {
        return [...parent::payload(), 'from' => $this->from, 'to' => $this->to];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['uuid'], $payload['stardust_field_id'], $payload['label'], $payload['from'], $payload['to']);
    }
}
