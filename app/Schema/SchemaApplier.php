<?php

namespace App\Schema;

use App\Models\SchemaChange;
use App\Models\SchemaField;
use App\Models\SchemaModel;
use App\Schema\Operations\AddField;
use App\Schema\Operations\DeleteField;
use App\Schema\Operations\Demote;
use App\Schema\Operations\FieldOperation;
use App\Schema\Operations\MetadataOnly;
use App\Schema\Operations\Operation;
use App\Schema\Operations\Promote;
use App\Schema\Operations\RenameField;
use App\Schema\Operations\RetypeField;
use App\StarDust\NotOwnedException;
use App\StarDust\StarDustService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StarDust\Schema\FieldDescription;
use Throwable;

/**
 * Carries out a save's operations, StarDust first and Laravel second.
 *
 * The two can't share a transaction, so the order decides what a crash
 * leaves behind. StarDust first means ss_fields never describes a change
 * StarDust hasn't made: at worst StarDust is one step ahead, and the
 * logged operation replays to catch Laravel up.
 *
 * Every operation is logged as pending before anything runs. A failure
 * marks that operation failed and the rest of the batch blocked, and
 * stops. retry() runs whatever is left. Each step is safe to repeat:
 * defineField() is get-or-create, deleteField() returns false once the
 * field is already going, and rename, retype, promote and demote check
 * describeModel() before calling StarDust.
 */
class SchemaApplier
{
    public function __construct(private readonly StarDustService $stardust) {}

    /**
     * Logs the operations as a new batch and runs it.
     *
     * @param  list<Operation>  $operations
     * @return string The batch id.
     */
    public function apply(SchemaModel $model, array $operations): string
    {
        $batch = (string) Str::uuid();

        foreach ($operations as $operation) {
            SchemaChange::create([
                'batch' => $batch,
                'model_id' => $model->id,
                'op' => $operation->kind(),
                'field_id' => $operation->fieldUuid(),
                'payload' => $operation->payload(),
            ]);
        }

        $this->run($model, $batch);

        return $batch;
    }

    /**
     * Runs a batch's failed, blocked and stranded pending operations again,
     * in their original order.
     */
    public function retry(SchemaModel $model, string $batch): void
    {
        $this->run($model, $batch);
    }

    /**
     * @return Collection<int, SchemaChange>
     */
    public function changes(string $batch): Collection
    {
        return SchemaChange::query()->where('batch', $batch)->orderBy('id')->get();
    }

    private function run(SchemaModel $model, string $batch): void
    {
        $changes = SchemaChange::query()
            ->where('batch', $batch)
            ->whereIn('status', SchemaChange::UNFINISHED)
            ->orderBy('id')
            ->get();

        $applied = false;

        foreach ($changes as $i => $change) {
            $change->attempts++;

            try {
                $this->applyOne($model, Operation::restore($change->op, $change->payload ?? []));
            } catch (Throwable $e) {
                if (! SchemaException::isExpected($e)) {
                    report($e);
                }

                $change->fill(['status' => SchemaChange::FAILED, 'error' => SchemaException::describe($e)])->save();

                SchemaChange::query()
                    ->whereIn('id', $changes->slice($i + 1)->modelKeys())
                    ->update(['status' => SchemaChange::BLOCKED]);

                break;
            }

            $change->fill(['status' => SchemaChange::DONE, 'error' => null, 'applied_at' => now()])->save();
            $applied = true;
        }

        if ($applied) {
            $model->increment('schema_rev');
        }
    }

    private function applyOne(SchemaModel $model, Operation $operation): void
    {
        match (true) {
            $operation instanceof MetadataOnly => $this->metadata($model, $operation),
            $operation instanceof AddField => $this->add($model, $operation),
            $operation instanceof RenameField => $this->rename($model, $operation),
            $operation instanceof RetypeField => $this->retype($model, $operation),
            $operation instanceof Promote => $this->refilter($model, $operation, true),
            $operation instanceof Demote => $this->refilter($model, $operation, false),
            $operation instanceof DeleteField => $this->delete($model, $operation),
            default => throw new SchemaException('Unknown schema operation '.$operation->kind().'.'),
        };
    }

    /**
     * Laravel's writes share one transaction. StarDust uses its own PDO, so
     * the model rename before it is not part of it, and it is synchronous
     * and a no-op when repeated.
     */
    private function metadata(SchemaModel $model, MetadataOnly $operation): void
    {
        if ($operation->renamesModel()) {
            $this->stardust->renameModel($model->stardust_model_id, $operation->model['slug']);
        }

        DB::transaction(function () use ($model, $operation) {
            $model->fill($operation->model)->save();

            foreach ($operation->fields as $uuid => $columns) {
                $this->field($model, $uuid)?->fill($columns)->save();
            }
        });
    }

    /**
     * A new field always registers as non-filterable first, even when the
     * builder wants it filterable from birth, then promotes it as a
     * second step when it does.
     *
     * StarDust reserves an unmapped filterable field's slot only two ways:
     * the promote/retype lifecycle's own synchronous (or deferred)
     * reservation, or the ADR 0007 exhaustion fallback a write enqueues.
     * defineField()'s own `isFilterable` flag does neither — it only sets
     * the registry flag, so a field created filterable in one call would
     * sit in "indexing" forever on a model with no entries yet, with
     * nothing to ever reserve it a slot. Routing through promoteField()
     * gets the self-reserving path every other promotion uses.
     */
    private function add(SchemaModel $model, AddField $operation): void
    {
        $described = $this->stardust->defineField($model->stardust_model_id, $operation->key(), $operation->declaredType, false);

        $field = $this->field($model, $operation->uuid) ?? new SchemaField(['id' => $operation->uuid, 'model_id' => $model->id]);
        $field->fill([...$operation->field, 'filterable' => false, 'stardust_field_id' => $described->fieldId])->save();

        if ($operation->filterable() && ! $described->isFilterable) {
            $this->stardust->promoteField($model->stardust_model_id, $described->fieldId);
        }

        if ($operation->filterable()) {
            $field->fill(['filterable' => true])->save();
        }
    }

    private function rename(SchemaModel $model, RenameField $operation): void
    {
        if ($this->described($model, $operation)->name !== $operation->to) {
            $this->stardust->renameField($model->stardust_model_id, $operation->stardustFieldId, $operation->to);
        }

        $this->field($model, $operation->uuid)?->fill(['key' => $operation->to])->save();
    }

    private function retype(SchemaModel $model, RetypeField $operation): void
    {
        if ($this->described($model, $operation)->declaredType !== $operation->toDeclared) {
            $this->stardust->retypeField($model->stardust_model_id, $operation->stardustFieldId, $operation->toDeclared);
        }

        $this->field($model, $operation->uuid)?->fill(['type' => $operation->type, 'settings' => $operation->settings])->save();
    }

    private function refilter(SchemaModel $model, FieldOperation $operation, bool $filterable): void
    {
        if ($this->described($model, $operation)->isFilterable !== $filterable) {
            $filterable
                ? $this->stardust->promoteField($model->stardust_model_id, $operation->stardustFieldId)
                : $this->stardust->demoteField($model->stardust_model_id, $operation->stardustFieldId);
        }

        $this->field($model, $operation->uuid)?->fill(['filterable' => $filterable])->save();
    }

    private function delete(SchemaModel $model, DeleteField $operation): void
    {
        $this->stardust->deleteField($model->stardust_model_id, $operation->stardustFieldId);

        $this->field($model, $operation->uuid)?->delete();
    }

    /**
     * The field as StarDust describes it now, which is how a replayed
     * operation knows whether StarDust already did its part.
     */
    private function described(SchemaModel $model, FieldOperation $operation): FieldDescription
    {
        foreach ($this->stardust->describeModel($model->stardust_model_id)->fields ?? [] as $field) {
            if ($field->fieldId === $operation->stardustFieldId) {
                return $field;
            }
        }

        throw NotOwnedException::field($model->stardust_model_id, $operation->stardustFieldId);
    }

    private function field(SchemaModel $model, string $uuid): ?SchemaField
    {
        return SchemaField::query()->where('model_id', $model->id)->find($uuid);
    }
}
