<?php

namespace App\Schema\Console;

use App\Models\SchemaChange;
use App\Models\Site;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use Illuminate\Console\Command;

class RetryCommand extends Command
{
    protected $signature = 'starsystem:schema:retry
        {batch? : The batch to retry. Without it, every unfinished save of every site is retried.}';

    protected $description = 'Retry schema saves that stopped part-way';

    public function handle(CurrentSite $current, SchemaManager $schema): int
    {
        // Batches are looked up across sites, then each one runs as its own
        // site, so every StarDust call carries the right tenant.
        $batches = SchemaChange::query()
            ->withoutGlobalScope('site')
            ->when(
                $this->argument('batch'),
                fn ($query, $batch) => $query->where('batch', $batch),
                fn ($query) => $query->whereIn('status', SchemaChange::UNFINISHED),
            )
            ->whereNotNull('batch')
            ->orderBy('id')
            ->get(['tenant_id', 'batch'])
            ->unique('batch');

        if ($batches->isEmpty()) {
            if ($this->argument('batch')) {
                $this->components->error('No such batch.');

                return self::FAILURE;
            }

            $this->components->info('Nothing to retry.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($batches as $row) {
            $current->set(Site::query()->findOrFail($row->tenant_id));

            $result = $schema->retry((string) $row->batch);

            if ($result->succeeded()) {
                $this->components->info("Batch {$row->batch}: done.");
            } else {
                $failed = true;
                $this->components->error("Batch {$row->batch}: {$result->error()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
