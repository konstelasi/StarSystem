<?php

namespace App\Activity\Console;

use App\Activity\ActivityPruner;
use Illuminate\Console\Command;

class PruneActivityCommand extends Command
{
    protected $signature = 'activity:prune
        {--limit=5000 : Most rows to delete in this run}';

    protected $description = 'Delete activity log rows older than starsystem.activity.keep_days';

    public function handle(ActivityPruner $pruner): int
    {
        $deleted = $pruner->prune(max(1, (int) $this->option('limit')));

        $this->components->info("Deleted {$deleted} activity row".($deleted === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
