<?php

namespace App\Schema\Operations;

use InvalidArgumentException;

/**
 * One step of a builder save. Operations are plain data: the schema diff
 * produces them, the applier carries them out, and payload() is what the
 * log stores, so a retry replays the step without diffing again.
 */
abstract class Operation
{
    /** In the order the applier runs them. */
    public const ORDER = ['metadata', 'delete', 'rename', 'retype', 'promote', 'demote', 'add'];

    /**
     * @return 'metadata'|'add'|'rename'|'retype'|'promote'|'demote'|'delete'
     */
    abstract public function kind(): string;

    abstract public function fieldUuid(): ?string;

    /**
     * One sentence for the save preview.
     */
    abstract public function summary(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    /**
     * Whether stored entries change, so the builder asks before saving.
     */
    public function affectsExistingData(): bool
    {
        return false;
    }

    /**
     * Whether stored data is lost for good.
     */
    public function destructive(): bool
    {
        return false;
    }

    /**
     * Rebuilds a logged operation from its kind and payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function restore(string $kind, array $payload): self
    {
        return match ($kind) {
            'metadata' => MetadataOnly::fromPayload($payload),
            'add' => AddField::fromPayload($payload),
            'rename' => RenameField::fromPayload($payload),
            'retype' => RetypeField::fromPayload($payload),
            'promote' => Promote::fromPayload($payload),
            'demote' => Demote::fromPayload($payload),
            'delete' => DeleteField::fromPayload($payload),
            default => throw new InvalidArgumentException("Unknown schema operation [{$kind}]."),
        };
    }

    /**
     * The entry in SavePreview.operations.
     *
     * @return array{op: string, field_uuid: ?string, summary: string, affects_existing_data: bool, destructive: bool}
     */
    public function toPreview(): array
    {
        return [
            'op' => $this->kind(),
            'field_uuid' => $this->fieldUuid(),
            'summary' => $this->summary(),
            'affects_existing_data' => $this->affectsExistingData(),
            'destructive' => $this->destructive(),
        ];
    }
}
