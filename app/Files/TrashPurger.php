<?php

namespace App\Files;

/**
 * Removes the bytes of files deleted more than files.trash_days ago, for
 * every site at once. Deleting only hides a file, so without this a shared
 * host's disk quota would fill with files nobody can see.
 *
 * Works in small batches so a daily run inside schedule:run stays short
 * even after a big clean-up.
 */
class TrashPurger
{
    public function __construct(private readonly FileStore $store) {}

    /**
     * @return int how many files were purged
     */
    public function purge(int $limit = 200): int
    {
        $files = File::withoutGlobalScope('site')
            ->onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(config()->integer('files.trash_days')))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($files as $file) {
            $this->store->purge($file);
        }

        return $files->count();
    }
}
