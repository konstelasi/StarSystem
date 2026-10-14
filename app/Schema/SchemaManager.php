<?php

namespace App\Schema;

use App\Models\SchemaChange;
use App\Models\SchemaModel;
use App\Schema\Operations\Operation;
use App\StarDust\StarDustService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Cache lock name prefix, followed by the model id.
     */
    public const SAVE_LOCK = 'schema-save:';

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

        if (($taken = $this->slugProblem($schema->slug)) !== null) {
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
     * $expectedRev is the schema_rev the builder opened. When given, a
     * model saved since then is an error, because diffing an older schema
     * against the stored one would undo that save.
     *
     * @return array{operations: list<array<string, mixed>>, errors: list<array{field_uuid: ?string, message: string}>}
     */
    public function preview(SchemaModel $model, ModelSchema $desired, ?int $expectedRev = null): array
    {
        return $this->plan($model, $desired, $expectedRev)->toPreview();
    }

    /**
     * One save per model at a time, so two that arrive together can't both
     * pass the schema_rev check before either has bumped it.
     */
    public function save(SchemaModel $model, ModelSchema $desired, ?int $expectedRev = null): SaveResult
    {
        return $this->locked($model, function () use ($model, $desired, $expectedRev) {
            // A save that finished between loading $model and taking the
            // lock has moved schema_rev on.
            $model->refresh();

            $plan = $this->plan($model, $desired, $expectedRev);

            if ($plan->hasErrors()) {
                return new SaveResult(SaveResult::INVALID, $model, $plan->toPreview());
            }

            if ($plan->isEmpty()) {
                return new SaveResult(SaveResult::UNCHANGED, $model, $plan->toPreview());
            }

            $batch = $this->applier->apply($model, $plan->operations);

            return SaveResult::fromBatch($model, $plan, $batch, $this->applier->changes($batch));
        });
    }

    /**
     * Runs what is left of a failed save, under the same lock as save(),
     * so a retry never overlaps a save of the same model.
     *
     * @throws ModelNotFoundException when the current site has no such batch
     */
    public function retry(string $batch): SaveResult
    {
        $first = $this->applier->changes($batch)->first() ?? throw (new ModelNotFoundException)->setModel(SchemaChange::class, [$batch]);
        $model = SchemaModel::query()->findOrFail($first->model_id);

        return $this->locked($model, function () use ($model, $batch) {
            $this->applier->retry($model, $batch);

            $changes = $this->applier->changes($batch);
            $operations = array_values($changes->map(fn (SchemaChange $change) => Operation::restore($change->op, $change->payload ?? []))->all());

            return SaveResult::fromBatch($model, new DiffResult($operations, []), $batch, $changes);
        });
    }

    /**
     * The model's save that stopped partway, if there is one: the batch to
     * retry, and the error that stopped it. Saving the model is refused
     * until it finishes (see plan()).
     *
     * @return array{batch: string, error: string}|null
     */
    public function unfinished(SchemaModel $model): ?array
    {
        $failed = SchemaChange::query()
            ->where('model_id', $model->id)
            ->where('status', SchemaChange::FAILED)
            ->latest('id')
            ->first();

        return $failed === null ? null : [
            'batch' => (string) $failed->batch,
            'error' => (string) $failed->error,
        ];
    }

    /**
     * Runs $work while holding the model's save lock, or reports that
     * another save holds it.
     *
     * @param  Closure(): SaveResult  $work
     */
    private function locked(SchemaModel $model, Closure $work): SaveResult
    {
        $lock = Cache::lock(self::SAVE_LOCK.$model->id, 120);

        if (! $lock->get()) {
            $busy = new DiffResult([], [['field_uuid' => null, 'message' => SchemaException::text('Another save of this model is running. Try again in a moment.')]]);

            return new SaveResult(SaveResult::INVALID, $model, $busy->toPreview());
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
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
        if ($model->isDeleting()) {
            throw new SchemaException(SchemaException::text('This model is already being deleted.'));
        }

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
    public function import(array|string $json, ?string $slug = null, ?string $label = null): SaveResult
    {
        $schema = ModelSchema::fromJson($json)->withFreshUuids();
        $schema = $slug === null ? $schema : $schema->withSlug($slug);
        $schema = $label === null ? $schema : $schema->withLabel($label);

        return $this->createModel($schema);
    }

    /**
     * Copies a model's fields into a new model under a new slug and label.
     * Fields get new UUIDs, the same as import().
     *
     * @throws SchemaException when the source model is being deleted
     */
    public function duplicate(SchemaModel $source, string $slug, string $label): SaveResult
    {
        if ($source->isDeleting()) {
            throw new SchemaException(SchemaException::text('This model is being deleted.'));
        }

        $schema = ModelSchema::fromModel($source)->withFreshUuids()->withSlug($slug)->withLabel($label);

        return $this->createModel($schema);
    }

    private function plan(SchemaModel $model, ModelSchema $desired, ?int $expectedRev = null): DiffResult
    {
        if ($model->isDeleting()) {
            return new DiffResult([], [['field_uuid' => null, 'message' => SchemaException::text('This model is being deleted.')]]);
        }

        if ($expectedRev !== null && $expectedRev !== $model->schema_rev) {
            return new DiffResult([], [['field_uuid' => null, 'message' => SchemaException::text('This model was saved in another tab or by someone else after you opened it. Copy anything you need from the JSON tab, then reload the page to see the latest version.')]]);
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

        if ($desired->slug !== $model->slug && ($taken = $this->slugProblem($desired->slug)) !== null) {
            $plan = $plan->withError(null, $taken);
        }

        return $plan;
    }

    public function slugProblem(string $slug): ?string
    {
        $existing = SchemaModel::query()->where('slug', $slug)->first();

        return match (true) {
            $existing === null => null,
            $existing->isDeleting() => SchemaException::text('A deleted model still holds the slug :slug while its entries are removed. Use it again once that finishes.', ['slug' => $slug]),
            default => SchemaException::text('Another model already uses the slug :slug.', ['slug' => $slug]),
        };
    }
}
