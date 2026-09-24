<?php

namespace App\Schema\Operations;

/**
 * A change of the type StarDust stores a field as. Only raised when the
 * StarDust type changes: text to email, say, is metadata.
 *
 * The JSON payload stays the source of truth. A filterable field's index
 * is rebuilt in the background, and values that don't convert are empty
 * in filters and sorting, not lost.
 */
final class RetypeField extends FieldOperation
{
    private const NAMES = [
        'string' => 'text',
        'int' => 'whole numbers',
        'numeric' => 'numbers',
        'datetime' => 'dates',
    ];

    /**
     * @param  array<string, mixed>  $settings  The field's new resolved settings.
     */
    public function __construct(
        string $uuid,
        int $stardustFieldId,
        string $label,
        public readonly string $fromDeclared,
        public readonly string $toDeclared,
        public readonly string $type,
        public readonly array $settings,
        public readonly bool $filterable,
    ) {
        parent::__construct($uuid, $stardustFieldId, $label);
    }

    public function kind(): string
    {
        return 'retype';
    }

    public function summary(): string
    {
        $summary = "Store \"{$this->label}\" as ".self::NAMES[$this->toDeclared].' instead of '.self::NAMES[$this->fromDeclared].'.';

        return $this->filterable
            ? $summary.' Its filter index is rebuilt in the background; values that don\'t convert stay in the entry but can\'t be filtered on.'
            : $summary;
    }

    public function affectsExistingData(): bool
    {
        return true;
    }

    public function payload(): array
    {
        return [
            ...parent::payload(),
            'from_declared' => $this->fromDeclared,
            'to_declared' => $this->toDeclared,
            'type' => $this->type,
            'settings' => $this->settings,
            'filterable' => $this->filterable,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            $payload['uuid'],
            $payload['stardust_field_id'],
            $payload['label'],
            $payload['from_declared'],
            $payload['to_declared'],
            $payload['type'],
            $payload['settings'],
            $payload['filterable'],
        );
    }
}
