<?php

namespace App\Entries;

use App\Models\SchemaModel;
use App\Models\Site;
use App\Sites\CurrentSite;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Deletes entries that have been in the trash longer than
 * starsystem.entries.trash_days, for every site, the way the media
 * library's purge does for files.
 *
 * A model whose trash field StarDust hasn't indexed yet is skipped: its
 * entries can't be filtered by trash time, and the next run will find it
 * indexed. The limit keeps one run inside schedule:run's minute on shared
 * hosting; whatever is left goes on the next run.
 */
class TrashPurger
{
    /**
     * Entries read per StarDust query.
     */
    private const BATCH = 100;

    public function __construct(
        private readonly CurrentSite $current,
        private readonly EntryManager $entries,
    ) {}

    /**
     * @return int how many entries were deleted
     */
    public function purge(int $limit = 500): int
    {
        $cutoff = CarbonImmutable::now()->subDays(config()->integer('starsystem.entries.trash_days'))->getTimestamp();
        $previous = $this->current->has() ? $this->current->get() : null;
        $purged = 0;

        try {
            foreach (Site::query()->orderBy('id')->get() as $site) {
                $this->current->set($site);

                $models = SchemaModel::query()->where('status', SchemaModel::ACTIVE)->orderBy('id')->get();

                foreach ($models as $model) {
                    if ($purged >= $limit) {
                        return $purged;
                    }

                    $purged += $this->purgeModel($model, $cutoff, $limit - $purged);
                }
            }

            return $purged;
        } finally {
            $previous === null ? $this->current->forget() : $this->current->set($previous);
        }
    }

    /**
     * One model's expired trash, up to $limit. A model StarDust refuses is
     * reported and skipped, so it can't stop the rest of the purge.
     */
    private function purgeModel(SchemaModel $model, int $cutoff, int $limit): int
    {
        $purged = 0;

        try {
            if (! $this->entries->trashIndexed($model)) {
                return 0;
            }

            // Deleted entries drop out of the next read, so each batch is
            // a fresh first page rather than a cursor.
            while ($purged < $limit) {
                $batch = $this->entries->trashedBefore($model, $cutoff, min(self::BATCH, $limit - $purged));

                if ($batch === []) {
                    break;
                }

                foreach ($batch as $entry) {
                    $this->entries->delete($model, $entry);
                    $purged++;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $purged;
    }
}
