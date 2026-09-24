<?php

namespace App\StarDust;

use App\Sites\CurrentSite;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use StarDust\Schema\ModelDescription;
use StarDust\StarDust;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;
use Throwable;

/**
 * The one door to StarDust. Every StarSystem call into the engine goes
 * through here, so an alpha API change is fixed in one place, and every
 * tenant-scoped call gets the current site's tenant id.
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
