<?php

namespace App\Schema\Operations;

/**
 * A new field: registered with StarDust, then written to ss_fields.
 * Nothing existing changes; entries simply don't have the key yet.
 */
final class AddField extends Operation
{
    /**
     * @param  array<string, mixed>  $field  The ss_fields columns to write.
     */
    public function __construct(
        public readonly string $uuid,
        public readonly array $field,
        public readonly string $declaredType,
    ) {}

    public function kind(): string
    {
        return 'add';
    }

    public function fieldUuid(): string
    {
        return $this->uuid;
    }

    public function key(): string
    {
        return $this->field['key'];
    }

    public function filterable(): bool
    {
        return (bool) $this->field['filterable'];
    }

    public function summary(): string
    {
        return "Add \"{$this->field['label']}\" ({$this->field['type']})".($this->filterable() ? ', filterable once indexed.' : '.');
    }

    public function payload(): array
    {
        return ['uuid' => $this->uuid, 'field' => $this->field, 'declared_type' => $this->declaredType];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['uuid'], $payload['field'], $payload['declared_type']);
    }
}
