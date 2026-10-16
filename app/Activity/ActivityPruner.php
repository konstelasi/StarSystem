<?php

namespace App\Activity;

use App\Models\Activity;
use Carbon\CarbonImmutable;

/**
 * Deletes log rows older than starsystem.activity.keep_days, for every
 * site. Zero keeps rows for good.
 *
 * The limit keeps one run inside schedule:run's minute on shared hosting.
 * Whatever is left goes on the next run.
 */
class ActivityPruner
{
    /**
     * Rows deleted per query, so one run never holds a long lock.
     */
    private const BATCH = 1000;

    /**
     * @return int how many rows were deleted
     */
    public function prune(int $limit = 5000): int
    {
        $days = config()->integer('starsystem.activity.keep_days');

        if ($days <= 0) {
            return 0;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $deleted = 0;

        while ($deleted < $limit) {
            $ids = Activity::withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(min(self::BATCH, $limit - $deleted))
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Activity::withoutGlobalScopes()->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }
}
