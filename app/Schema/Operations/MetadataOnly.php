<?php

namespace App\Schema\Operations;

/**
 * Everything StarDust doesn't store: the model's label, icon, group and
 * layout, and each field's label, help text, order, placement and
 * settings. The one StarDust call is a model rename, because the slug is
 * the StarDust model name; that rename is synchronous.
 */
final class MetadataOnly extends Operation
{
    /**
     * @param  array{slug: string, label: string, icon: ?string, group: ?string, layout: list<array<string, mixed>>}  $model
     * @param  array<string, array<string, mixed>>  $fields  uuid => the ss_fields columns to write
     * @param  list<string>  $changes  What changed, for the summary.
     */
    public function __construct(
        public readonly string $previousSlug,
        public readonly array $model,
        public readonly array $fields,
        public readonly array $changes,
    ) {}

    public function kind(): string
    {
        return 'metadata';
    }

    public function fieldUuid(): ?string
    {
        return null;
    }

    public function renamesModel(): bool
    {
        return $this->previousSlug !== $this->model['slug'];
    }

    public function summary(): string
    {
        return 'Update '.implode(', ', $this->changes).'.';
    }

    public function payload(): array
    {
        return [
            'previous_slug' => $this->previousSlug,
            'model' => $this->model,
            'fields' => $this->fields,
            'changes' => $this->changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self($payload['previous_slug'], $payload['model'], $payload['fields'], $payload['changes']);
    }
}
