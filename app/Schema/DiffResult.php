<?php

namespace App\Schema;

use App\Schema\Operations\Operation;

/**
 * What a save would do, and what stops it. The builder's two-step save
 * shows this before anything is written.
 */
final class DiffResult
{
    /**
     * @param  list<Operation>  $operations  In the order they run.
     * @param  list<array{field_uuid: ?string, message: string}>  $errors
     */
    public function __construct(
        public readonly array $operations,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function withError(?string $fieldUuid, string $message): self
    {
        return new self($this->operations, [...$this->errors, ['field_uuid' => $fieldUuid, 'message' => $message]]);
    }

    /**
     * The builder's SavePreview.
     *
     * @return array{
     *     operations: list<array{op: string, field_uuid: ?string, summary: string, affects_existing_data: bool, destructive: bool}>,
     *     errors: list<array{field_uuid: ?string, message: string}>,
     * }
     */
    public function toPreview(): array
    {
        return [
            'operations' => array_map(fn (Operation $operation) => $operation->toPreview(), $this->operations),
            'errors' => $this->errors,
        ];
    }
}
