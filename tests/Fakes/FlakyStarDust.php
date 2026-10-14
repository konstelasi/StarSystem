<?php

namespace Tests\Fakes;

use App\StarDust\StarDustService;
use RuntimeException;
use StarDust\Schema\FieldDescription;

/**
 * Counts every StarDust schema write and can fail the Nth one, like a
 * connection that drops half-way through a save.
 */
class FlakyStarDust extends StarDustService
{
    public int $calls = 0;

    public ?int $failOn = null;

    public function renameModel(int $modelId, string $newName): void
    {
        $this->count();
        parent::renameModel($modelId, $newName);
    }

    public function defineField(int $modelId, string $name, string $declaredType, bool $filterable): FieldDescription
    {
        $this->count();

        return parent::defineField($modelId, $name, $declaredType, $filterable);
    }

    public function renameField(int $modelId, int $fieldId, string $newName): void
    {
        $this->count();
        parent::renameField($modelId, $fieldId, $newName);
    }

    public function retypeField(int $modelId, int $fieldId, string $declaredType): void
    {
        $this->count();
        parent::retypeField($modelId, $fieldId, $declaredType);
    }

    public function promoteField(int $modelId, int $fieldId): void
    {
        $this->count();
        parent::promoteField($modelId, $fieldId);
    }

    public function demoteField(int $modelId, int $fieldId): void
    {
        $this->count();
        parent::demoteField($modelId, $fieldId);
    }

    public function deleteField(int $modelId, int $fieldId): bool
    {
        $this->count();

        return parent::deleteField($modelId, $fieldId);
    }

    private function count(): void
    {
        $this->calls++;

        if ($this->calls === $this->failOn) {
            throw new RuntimeException('StarDust went away');
        }
    }
}
