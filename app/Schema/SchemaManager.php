<?php

namespace App\Schema;

use App\Models\SchemaChange;
use App\Models\SchemaModel;
use App\Schema\Operations\Operation;
use App\StarDust\StarDustService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The builder's entry point to the schema layer, for the current site.
 *
 * A save is two steps: preview() lists what would happen, including what
 * touches existing entries, and save() does it. Both run the same diff,
 * so what the builder confirmed is what gets applied.
 */
class SchemaManager
{
    public function __construct(
        private readonly StarDustService $stardust,
        private readonly SchemaDiff $diff,
        private readonly SchemaApplier $applier,
    ) {}

    /**
     * Creates a model and its fields. Nothing is written when the schema
     * has errors.
     */
    public function createModel(ModelSchema $schema): SaveResult
    {
        $this->forgetPurgedModels();

        $empty = new ModelSchema($schema->slug, $schema->label, $schema->icon, $schema->group, $schema->layout);
        $plan = $this->diff->diff($empty, $schema);

        if (($taken = $this->slugTaken($schema->slug)) !== null) {
            $plan = $plan->withError(null, $taken);
        }

        if ($plan->hasErrors()) {
            return new SaveResult(SaveResult::INVALID, null, $plan->toPreview());
        }

        // Get-or-create by name, so a creation that failed after this call
        // adopts the same StarDust model when it is tried again.
        $stardustId = $this->stardust->createModel($schema->slug);

        $model = SchemaModel::create([
            'stardust_model_id' => $stardustId,
            'slug' => $schema->slug,
            'label' => $schema->label,
            'icon' => $schema->icon,
            'group' => $schema->group,
            'layout' => $schema->layout,
        ]);

        if ($plan->isEmpty()) {
            return new SaveResult(SaveResult::DONE, $model, $plan->toPreview());
        }

        $batch = $this->applier->apply($model, $plan->operations);

        return SaveResult::fromBatch($model, $plan, $batch, $this->applier->changes($batch));
    }

    /**
     * SavePreview: the operations a save would run and what stops it.
     * Writes nothing.
     *
     * @return array{operations: list<array<string, mixed>>, errors: list<array{field_uuid: ?string, message: string}>}
     */
    public function preview(SchemaModel $model, ModelSchema $desired): array
    {
        return $this->plan($model, $desired)->toPreview();
    }

    public function save(SchemaModel $model, ModelSchema $desired): SaveResult
    {
        $plan = $this->plan($model, $desired);

        if ($plan->hasErrors()) {
            return new SaveResult(SaveResult::INVALID, $model, $plan->toPreview());
        }

        if ($plan->isEmpty()) {
            return new SaveResult(SaveResult::UNCHANGED, $model, $plan->toPreview());
        }

        $batch = $this->applier->apply($model, $plan->operations);

        return SaveResult::fromBatch($model, $plan, $batch, $this->applier->changes($batch));
    }

    /**
     * Runs what is left of a failed save.
     *
     * @throws ModelNotFoundException when the current site has no such batch
     */
    public function retry(string $batch): SaveResult
    {
        $changes = $this->applier->changes($batch);
        $first = $changes->first() ?? throw (new ModelNotFoundException)->setModel(SchemaChange::class, [$batch]);
        $model = SchemaModel::query()->findOrFail($first->model_id);

        $this->applier->retry($model, $batch);

        $operations = array_values($changes->map(fn (SchemaChange $change) => Operation::restore($change->op, $change->payload ?? []))->all());

        return SaveResult::fromBatch($model, new DiffResult($operations, []), $batch, $this->applier->changes($batch));
    }

    /**
     * The builder's badge for every field: uuid => ready, indexing,
     * renaming, retyping, deleting or waiting.
     *
     * "waiting" means an operation of the field's last save has not been
     * applied (it failed, is blocked, or is still running). The StarDust
     * states come from StarDustService::fieldStates().
     *
     * @return array<string, 'ready'|'indexing'|'renaming'|'retyping'|'deleting'|'waiting'>
     */
    public function states(SchemaModel $model): array
    {
        $fields = $model->fields()->get();

        if ($model->isDeleting()) {
            return $fields->mapWithKeys(fn ($field) => [$field->id => 'deleting'])->all();
        }

        $stardust = $this->stardust->fieldStates($model->stardust_model_id);
        $unfinished = SchemaChange::query()
            ->where('model_id', $model->id)
            ->whereIn('status', SchemaChange::UNFINISHED)
            ->whereNotNull('field_id')
            ->pluck('field_id')
            ->flip();

        $states = [];
        foreach ($fields as $field) {
            $state = $stardust[$field->stardust_field_id]['state'] ?? 'waiting';

            $states[$field->id] = in_array($state, ['ready', 'indexing'], true) && $unfinished->has($field->id)
                ? 'waiting'
                : $state;
        }

        foreach ($unfinished->keys() as $uuid) {
            $states[$uuid] ??= 'waiting';
        }

        return $states;
    }

    /**
     * Deletes a model and, in the background, every entry in it. The
     * ss_models row stays, as "deleting", until StarDust has purged the
     * entries and released the slug.
     *
     * @throws SchemaException when StarDust refuses, e.g. a field is still
     *                         being renamed
     */
    public function deleteModel(SchemaModel $model): void
    {
        try {
            $this->stardust->deleteModel($model->stardust_model_id);
        } catch (Throwable $e) {
            throw new SchemaException(SchemaException::describe($e), previous: $e);
        }

        DB::transaction(function () use ($model) {
            $model->fields()->delete();
            $model->update(['status' => SchemaModel::DELETING]);
        });
    }

    /**
     * Drops the rows of deleted models StarDust has finished purging, so
     * their slugs can be used again.
     */
    public function forgetPurgedModels(): void
    {
        SchemaModel::query()->where('status', SchemaModel::DELETING)->get()
            ->filter(fn (SchemaModel $model) => $this->stardust->modelPurged($model->stardust_model_id))
            ->each(fn (SchemaModel $model) => $model->delete());
    }

    /**
     * ModelSchemaJson, the export format.
     *
     * @return array<string, mixed>
     */
    public function export(SchemaModel $model): array
    {
        return ModelSchema::fromModel($model)->toJson();
    }

    /**
     * Creates a model from export JSON. Fields get new UUIDs, because field
     * ids are global and the source may be this very install.
     *
     * @param  array<array-key, mixed>|string  $json
     *
     * @throws InvalidSchemaException
     */
    public function import(array|string $json, ?string $slug = null): SaveResult
    {
        $schema = ModelSchema::fromJson($json)->withFreshUuids();

        return $this->createModel($slug === null ? $schema : $schema->withSlug($slug));
    }

    private function plan(SchemaModel $model, ModelSchema $desired): DiffResult
    {
        if ($model->isDeleting()) {
            return new DiffResult([], [['field_uuid' => null, 'message' => SchemaException::text('This model is being deleted.')]]);
        }

        $unfinished = SchemaChange::query()
            ->where('model_id', $model->id)
            ->whereIn('status', [SchemaChange::FAILED, SchemaChange::BLOCKED])
            ->exists();

        if ($unfinished) {
            return new DiffResult([], [['field_uuid' => null, 'message' => SchemaException::text('The last save of this model did not finish. Retry it before saving again.')]]);
        }

        $fields = $model->fields()->get();
        $stardust = $this->stardust->fieldStates($model->stardust_model_id);

        $heldKeys = [];
        foreach ($stardust as $field) {
            if ($field['state'] === 'deleting') {
                $heldKeys[] = $field['name'];
            }

            if ($field['previous_name'] !== null) {
                $heldKeys[] = $field['previous_name'];
            }
        }

        $plan = $this->diff->diff(
            ModelSchema::fromModel($model),
            $desired,
            $fields->mapWithKeys(fn ($field) => [$field->id => (int) $field->stardust_field_id])->all(),
            $this->states($model),
            $heldKeys,
        );

        if ($desired->slug !== $model->slug && ($taken = $this->slugTaken($desired->slug)) !== null) {
            $plan = $plan->withError(null, $taken);
        }

        return $plan;
    }

    private function slugTaken(string $slug): ?string
    {
        $existing = SchemaModel::query()->where('slug', $slug)->first();

        return match (true) {
            $existing === null => null,
            $existing->isDeleting() => SchemaException::text('A deleted model still holds the slug :slug while its entries are removed. Use it again once that finishes.', ['slug' => $slug]),
            default => SchemaException::text('Another model already uses the slug :slug.', ['slug' => $slug]),
        };
    }
}
