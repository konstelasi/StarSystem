<?php

namespace App\StarDust;

use App\Models\SchemaModel;
use App\Sites\CurrentSite;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use StarDust\Exception\EntryNotFoundException;
use StarDust\Read\Entry;
use StarDust\Read\EntryPage;
use StarDust\Read\EntryQuery;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Schema\FieldDescription;
use StarDust\Schema\ModelDescription;
use StarDust\StarDust;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriteResult;
use Throwable;

/**
 * The one door to StarDust. Every StarSystem call into the engine goes
 * through here, so an alpha API change is fixed in one place, and every
 * tenant-scoped call gets the current site's tenant id.
 *
 * Schema calls also check that the site owns the model (and field) first,
 * because not every StarDust schema call takes a tenant id.
 */
class StarDustService
{
    public const STATUS_TABLES = [
        'stardust_sync_queue',
        'stardust_reconciler_dlq',
        'stardust_slot_assignments',
        'stardust_import_jobs',
        'stardust_export_jobs',
    ];

    public function __construct(
        private readonly Container $container,
        private readonly CurrentSite $site,
    ) {}

    /**
     * The engine, built on first use.
     */
    public function engine(): StarDust
    {
        return $this->container->make(StarDust::class);
    }

    /**
     * Provision StarDust's tables. Safe to run more than once.
     */
    public function bootstrap(): void
    {
        $this->engine()->bootstrap();
    }

    public function describeModel(int $modelId): ?ModelDescription
    {
        return $this->engine()->describeModel($this->site->tenantId(), $modelId);
    }

    /**
     * Register a model under the current site. Get-or-create by name, so a
     * save that failed after this call adopts the same model on retry.
     */
    public function createModel(string $name): int
    {
        return $this->engine()->schemaBuilder()->defineModel($this->site->tenantId(), $name);
    }

    /**
     * Synchronous: a StarDust model name is a label, so nothing waits on
     * the tick. Renaming to the current name is a no-op.
     */
    public function renameModel(int $modelId, string $newName): void
    {
        $this->owned($modelId);

        $this->engine()->renameModel($this->site->tenantId(), $modelId, $newName);
    }

    /**
     * Severs the model at once; the tick purges its entries. False when
     * the model is already being deleted, so a retry is harmless.
     *
     * Ownership goes through ss_models only: describeModel() already
     * reports a deleting model as missing, and StarDust scopes this call to
     * the tenant itself.
     */
    public function deleteModel(int $modelId): bool
    {
        $this->assertRegistered($modelId);

        return $this->engine()->deleteModel($this->site->tenantId(), $modelId);
    }

    /**
     * True once the tick has purged a deleted model and StarDust has
     * released its name.
     */
    public function modelPurged(int $modelId): bool
    {
        $this->assertRegistered($modelId);

        $statement = $this->engine()->pdo()->prepare('SELECT 1 FROM stardust_models WHERE id = ? AND tenant_id = ?');
        $statement->execute([$modelId, $this->site->tenantId()]);

        return $statement->fetchColumn() === false;
    }

    /**
     * Register a field and return it as StarDust now describes it.
     *
     * defineField() takes no tenant id, so the model's ownership is checked
     * first. It is also get-or-create by name and ignores the type of an
     * existing field, so the result is compared with what was asked for.
     *
     * @throws FieldConflictException when a field of that name exists with a
     *                                different type or filterability
     */
    public function defineField(int $modelId, string $name, string $declaredType, bool $filterable): FieldDescription
    {
        $this->owned($modelId);

        $fieldId = $this->engine()->schemaBuilder()->defineField($modelId, $name, $declaredType, $filterable);

        $model = $this->describeModel($modelId) ?? throw NotOwnedException::model($modelId);
        $field = $this->findField($model, $fieldId) ?? throw NotOwnedException::field($modelId, $fieldId);

        if ($field->declaredType !== $declaredType || $field->isFilterable !== $filterable) {
            throw new FieldConflictException($name, $field, $declaredType, $filterable);
        }

        return $field;
    }

    /**
     * Returns once the registry has the new name; the tick rewrites stored
     * entries. Reads see the new name at once.
     */
    public function renameField(int $modelId, int $fieldId, string $newName): void
    {
        $this->ownedField($modelId, $fieldId);

        $this->engine()->renameField($this->site->tenantId(), $fieldId, $newName);
    }

    /**
     * A filterable field backfills its new slot on the tick; a JSON-only
     * field is retyped on return.
     */
    public function retypeField(int $modelId, int $fieldId, string $declaredType): void
    {
        $this->ownedField($modelId, $fieldId);

        $this->engine()->retypeField($this->site->tenantId(), $fieldId, $declaredType);
    }

    /**
     * The field becomes filterable once the tick has backfilled its slot.
     */
    public function promoteField(int $modelId, int $fieldId): void
    {
        $this->ownedField($modelId, $fieldId);

        $this->engine()->promoteFieldToFilterable($this->site->tenantId(), $fieldId);
    }

    /**
     * Effective on return; there is nothing to backfill.
     */
    public function demoteField(int $modelId, int $fieldId): void
    {
        $this->ownedField($modelId, $fieldId);

        $this->engine()->demoteFieldFromFilterable($this->site->tenantId(), $fieldId);
    }

    /**
     * Severs the field at once; the tick removes its values from stored
     * entries. False when the field is already being deleted or gone, so a
     * retry is harmless.
     */
    public function deleteField(int $modelId, int $fieldId): bool
    {
        if ($this->findField($this->owned($modelId), $fieldId) === null) {
            return false;
        }

        return $this->engine()->deleteField($this->site->tenantId(), $fieldId);
    }

    /**
     * Every field StarDust holds for a model, including fields still being
     * deleted, with the state the builder shows as a badge.
     *
     * describeModel() reports only isFilterable and isIndexed, and omits
     * deleting fields, so this reads the registry and the backfill
     * checkpoints directly, read-only, like status(). The job names are
     * StarDust's own constants: rename_field_{id}, retype_field_{id}
     * (promotions run the retype lifecycle too) and delete_field_{id}.
     *
     * It reads through the engine's PDO rather than Laravel's connection:
     * StarDust commits on its own connection, and a Laravel transaction
     * that is already open would keep showing its older snapshot.
     *
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     previous_name: ?string,
     *     declared_type: string,
     *     filterable: bool,
     *     indexed: bool,
     *     state: 'ready'|'indexing'|'renaming'|'retyping'|'deleting',
     * }>
     */
    public function fieldStates(int $modelId): array
    {
        $this->owned($modelId);

        $pdo = $this->engine()->pdo();

        $fields = $pdo->prepare(
            'SELECT f.id, f.name, f.previous_name, f.declared_type, f.is_filterable, f.deleted_at,'
            ." EXISTS (SELECT 1 FROM stardust_slot_assignments a WHERE a.field_id = f.id AND a.status IN ('assigned', 'ready')) AS indexed"
            .' FROM stardust_fields f WHERE f.model_id = ? ORDER BY f.id'
        );
        $fields->execute([$modelId]);
        $rows = $fields->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $jobs[] = RenameCheckpointRepository::JOB_NAME_PREFIX.$id;
            $jobs[] = RetypeCheckpointRepository::jobNameFor($id);
        }

        $checkpoints = $pdo->prepare(
            'SELECT job_name, source_declared_type FROM backfill_checkpoints'
            ." WHERE status = 'running' AND job_name IN (".implode(',', array_fill(0, count($jobs), '?')).')'
        );
        $checkpoints->execute($jobs);
        $running = [];
        foreach ($checkpoints->fetchAll(PDO::FETCH_ASSOC) as $checkpoint) {
            $running[(string) $checkpoint['job_name']] = $checkpoint['source_declared_type'];
        }

        $states = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $declaredType = (string) $row['declared_type'];
            $filterable = (bool) $row['is_filterable'];
            $indexed = (bool) $row['indexed'];
            $retypeJob = RetypeCheckpointRepository::jobNameFor($id);

            $state = match (true) {
                $row['deleted_at'] !== null => 'deleting',
                $row['previous_name'] !== null,
                array_key_exists(RenameCheckpointRepository::JOB_NAME_PREFIX.$id, $running) => 'renaming',
                array_key_exists($retypeJob, $running)
                    && $running[$retypeJob] !== null
                    && $running[$retypeJob] !== $declaredType => 'retyping',
                array_key_exists($retypeJob, $running),
                $filterable && ! $indexed => 'indexing',
                default => 'ready',
            };

            $states[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'previous_name' => $row['previous_name'] === null ? null : (string) $row['previous_name'],
                'declared_type' => $declaredType,
                'filterable' => $filterable,
                'indexed' => $indexed,
                'state' => $state,
            ];
        }

        return $states;
    }

    /**
     * Registers a new entry under the current site and returns its id.
     *
     * @param  array<string, mixed>  $fields
     */
    public function writeEntry(int $modelId, array $fields): int
    {
        $this->owned($modelId);

        return $this->engine()->write(new EntryPayload($this->site->tenantId(), $modelId, $fields))->entryId;
    }

    /**
     * A point read by id, or null if it doesn't exist, belongs to another
     * site, is soft-deleted, or belongs to a different model than asked.
     * StarDust's own get() checks only the tenant, not the model, because
     * an entry id isn't scoped to one model.
     */
    public function getEntry(int $modelId, int $entryId): ?Entry
    {
        $this->owned($modelId);

        $entry = $this->engine()->get($this->site->tenantId(), $entryId);

        return $entry !== null && $entry->modelId === $modelId ? $entry : null;
    }

    /**
     * A full replace: any field left out of $fields is cleared from the
     * entry. The caller is responsible for carrying forward whatever it
     * doesn't mean to change.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws EntryNotFoundException when the entry
     *                                doesn't exist, belongs to another site, or is already deleted
     */
    public function updateEntry(int $modelId, int $entryId, array $fields): EntryWriteResult
    {
        $this->owned($modelId);

        return $this->engine()->updateEntry($this->site->tenantId(), $entryId, $fields);
    }

    /**
     * Soft-deletes an entry for good: StarDust has no restore. False when
     * the entry is already gone, so a retry is harmless.
     */
    public function deleteEntry(int $modelId, int $entryId): bool
    {
        $this->owned($modelId);

        return $this->engine()->deleteEntry($this->site->tenantId(), $entryId);
    }

    /**
     * A cursor-paginated, optionally filtered and sorted read of a model's
     * entries.
     */
    public function listEntries(EntryQuery $query): EntryPage
    {
        $this->owned($query->modelId);

        return $this->engine()->read($query);
    }

    /**
     * The reserved field StarSystem uses to mark a trashed entry: get- or
     * create it, and make sure it is filterable so the entries list can
     * exclude trashed entries by default. Idempotent, safe to call often.
     *
     * A new field always registers non-filterable first and is promoted as
     * a second step — see SchemaApplier::add()'s docblock for why. This
     * never touches ss_fields, so the field stays invisible to the
     * builder, the same reason SchemaDiff refuses a builder-entered key
     * starting with "_".
     */
    public function ensureTrashField(int $modelId): void
    {
        $described = $this->defineField($modelId, '_trashed', 'int', false);

        if (! $described->isFilterable) {
            $this->promoteField($modelId, $described->fieldId);
        }
    }

    /**
     * The ownership check every schema call makes: the model must be one of
     * this site's ss_models and StarDust must report it for this tenant.
     * Either alone can be fooled, by an ss_models row pointing at another
     * tenant's model or by a StarDust model StarSystem never registered.
     *
     * @throws NotOwnedException
     */
    private function owned(int $modelId): ModelDescription
    {
        $this->assertRegistered($modelId);

        return $this->describeModel($modelId) ?? throw NotOwnedException::model($modelId);
    }

    /**
     * @throws NotOwnedException
     */
    private function ownedField(int $modelId, int $fieldId): FieldDescription
    {
        return $this->findField($this->owned($modelId), $fieldId) ?? throw NotOwnedException::field($modelId, $fieldId);
    }

    /**
     * @throws NotOwnedException
     */
    private function assertRegistered(int $modelId): void
    {
        if (! SchemaModel::query()->where('stardust_model_id', $modelId)->exists()) {
            throw NotOwnedException::model($modelId);
        }
    }

    private function findField(ModelDescription $model, int $fieldId): ?FieldDescription
    {
        foreach ($model->fields as $field) {
            if ($field->fieldId === $fieldId) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The database server as StarDust sees it. StarDust refuses to run
     * below MySQL 8.0.13 or MariaDB 10.11, so this is what the installer
     * and the health panel check.
     *
     * @return array{version: string, engine: ?string, supported: bool, error: ?string}
     */
    public function server(): array
    {
        $version = (string) $this->connection()->selectOne('select version() as v')->v;

        try {
            $engine = ServerEngineDetector::detect($this->connection()->getPdo());

            return [
                'version' => $version,
                'engine' => $engine === ServerEngine::MARIADB ? 'MariaDB' : 'MySQL',
                'supported' => true,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['version' => $version, 'engine' => null, 'supported' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * StarDust has no public status API in 0.3.0-alpha.1, so this reads its
     * operational tables directly, read-only. It is a candidate for an
     * upstream API, and the only place that knows these table names.
     *
     * @return array{
     *     bootstrapped: bool,
     *     sync_queue: int,
     *     oldest_sync_at: ?string,
     *     dead_letters: int,
     *     slots: array<string, int>,
     *     imports: array<string, int>,
     *     exports: array<string, int>,
     * }
     */
    public function status(): array
    {
        $db = $this->connection();

        $bootstrapped = collect(self::STATUS_TABLES)->every(fn ($table) => $db->getSchemaBuilder()->hasTable($table));

        if (! $bootstrapped) {
            return [
                'bootstrapped' => false,
                'sync_queue' => 0,
                'oldest_sync_at' => null,
                'dead_letters' => 0,
                'slots' => [],
                'imports' => [],
                'exports' => [],
            ];
        }

        $tenant = $this->site->tenantId();

        $byStatus = fn ($query) => $query->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)->all();

        return [
            'bootstrapped' => true,
            'sync_queue' => $db->table('stardust_sync_queue')->count(),
            'oldest_sync_at' => $db->table('stardust_sync_queue')->min('created_at'),
            'dead_letters' => $db->table('stardust_reconciler_dlq')->count(),
            'slots' => $byStatus($db->table('stardust_slot_assignments')),
            'imports' => $byStatus($db->table('stardust_import_jobs')->where('tenant_id', $tenant)),
            'exports' => $byStatus($db->table('stardust_export_jobs')->where('tenant_id', $tenant)),
        ];
    }

    private function connection(): Connection
    {
        return DB::connection(config('stardust.connection'));
    }
}
