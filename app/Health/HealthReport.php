<?php

namespace App\Health;

use App\Models\TickRun;
use App\StarDust\StarDustService;
use App\StarDust\TickPause;
use Carbon\CarbonImmutable;

/**
 * Everything the health panel shows. On cron-only hosting a new filterable
 * field only becomes ready one or more cron runs later, so without this
 * panel consumers report that "filtering is broken".
 */
class HealthReport
{
    public function __construct(
        private readonly StarDustService $stardust,
        private readonly TickPause $pause,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => config('stardust.profile'),
            'tick' => $this->tick(),
            // Paused ticks are still recorded, so a pause never reads as a
            // missing cron job; this says why nothing is being processed.
            'paused' => $this->pause->info(),
            'server' => $this->stardust->server(),
            'stardust' => $this->stardust->status(),
            'paths' => $this->paths(),
            'tickUrlEnabled' => (string) config('stardust.tick.secret') !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tick(): array
    {
        $recent = TickRun::query()->latest('id')->limit(20)->get();
        $last = $recent->first();
        $staleAfter = (int) config('stardust.tick.stale_after_minutes');

        $minutesSince = $last?->started_at
            ? (int) floor($last->started_at->diffInMinutes(CarbonImmutable::now(), true))
            : null;

        return [
            // The server profile's daemons don't record ticks.
            'expected' => config('stardust.profile') === 'shared',
            'staleAfterMinutes' => $staleAfter,
            'minutesSinceLast' => $minutesSince,
            'stale' => $last === null || $minutesSince >= $staleAfter,
            'recent' => $recent->map(fn (TickRun $run) => [
                'id' => $run->id,
                'trigger' => $run->trigger,
                'startedAt' => $run->started_at->toIso8601String(),
                'elapsedSeconds' => $run->elapsed_seconds,
                'budgetSeconds' => $run->budget_seconds,
                'rounds' => $run->rounds,
                'stopReason' => $run->stop_reason,
                'error' => $run->error,
            ])->all(),
        ];
    }

    /**
     * StarDust's working directories must be writable and never web-served.
     *
     * @return list<array{name: string, path: string, writable: bool, public: bool}>
     */
    private function paths(): array
    {
        $public = $this->normalize(public_path());

        $paths = [
            'StarDust artifacts' => config()->string('stardust.artifact_dir'),
            'StarDust pid files' => config()->string('stardust.pid_dir'),
            'Storage' => storage_path(),
        ];

        $checks = [];

        foreach ($paths as $name => $path) {
            $checks[] = [
                'name' => $name,
                'path' => $path,
                'writable' => is_dir($path) && is_writable($path),
                'public' => str_starts_with($this->normalize($path).'/', $public.'/'),
            ];
        }

        return $checks;
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    }
}
