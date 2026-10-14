<?php

namespace App\Health;

use App\Models\TickRun;
use Carbon\CarbonImmutable;

/**
 * Whether StarDust's background work is running on time. The health panel
 * shows the details; every admin page shows a banner when it is late,
 * since a new filterable field otherwise waits at "Indexing" with no hint.
 *
 * Paused ticks are still recorded, so an update in progress never reads
 * as late.
 */
class TickStatus
{
    private ?TickRun $last = null;

    private bool $loaded = false;

    /**
     * The server profile runs StarDust's daemons, which record no ticks.
     */
    public function expected(): bool
    {
        return config('stardust.profile') === 'shared';
    }

    public function staleAfterMinutes(): int
    {
        return (int) config('stardust.tick.stale_after_minutes');
    }

    /**
     * Whole minutes since the last tick started, or null when it never has.
     */
    public function minutesSinceLast(): ?int
    {
        $started = $this->last()?->started_at;

        return $started === null ? null : (int) floor($started->diffInMinutes(CarbonImmutable::now(), true));
    }

    public function stale(): bool
    {
        $minutes = $this->minutesSinceLast();

        return $minutes === null || $minutes >= $this->staleAfterMinutes();
    }

    /**
     * Expected and stale: the case the owner has to fix.
     */
    public function late(): bool
    {
        return $this->expected() && $this->stale();
    }

    private function last(): ?TickRun
    {
        if (! $this->loaded) {
            $this->last = TickRun::query()->latest('id')->first();
            $this->loaded = true;
        }

        return $this->last;
    }
}
