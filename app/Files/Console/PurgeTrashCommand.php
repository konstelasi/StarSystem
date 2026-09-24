<?php

namespace App\Files\Console;

use App\Files\TrashPurger;
use Illuminate\Console\Command;

class PurgeTrashCommand extends Command
{
    protected $signature = 'files:purge-trash
        {--limit=200 : Most files to purge in this run}';

    protected $description = 'Remove the bytes of files deleted longer ago than files.trash_days';

    public function handle(TrashPurger $purger): int
    {
        $purged = $purger->purge(max(1, (int) $this->option('limit')));

        $this->components->info("Purged {$purged} file(s) from the trash.");

        return self::SUCCESS;
    }
}
