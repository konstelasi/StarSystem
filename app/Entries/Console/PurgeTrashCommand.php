<?php

namespace App\Entries\Console;

use App\Entries\TrashPurger;
use Illuminate\Console\Command;

class PurgeTrashCommand extends Command
{
    protected $signature = 'entries:purge-trash
        {--limit=500 : Most entries to delete in this run}';

    protected $description = 'Delete entries trashed longer ago than starsystem.entries.trash_days';

    public function handle(TrashPurger $purger): int
    {
        $purged = $purger->purge(max(1, (int) $this->option('limit')));

        $this->components->info("Deleted {$purged} entr".($purged === 1 ? 'y' : 'ies').' from the trash.');

        return self::SUCCESS;
    }
}
