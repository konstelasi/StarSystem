<?php

namespace App\StarDust;

use App\Models\TickRun;
use DomainException;
use Illuminate\Support\Facades\Log;
use StarDust\Daemon\TickStopReason;
use Throwable;

/**
 * Runs one budgeted StarDust tick and records it for the health panel.
 * The scheduler, the secret tick URL and `stardust:tick` all come here.
 */
class TickRunner
{
    public const TRIGGERS = ['cron', 'url', 'manual'];

    public function __construct(private readonly StarDustFactory $factory) {}

    public function run(string $trigger, ?int $budget = null, bool $advisories = false): TickRun
    {
        if (config('stardust.profile') === 'server') {
            throw new DomainException(
                'The server profile runs the StarDust daemons, so the tick is disabled. StarDust must never run both.'
            );
        }

        $exports = (bool) config('stardust.tick.exports');

        $run = TickRun::create([
            'trigger' => $trigger,
            'started_at' => now(),
        ]);

        try {
            // A fresh engine per tick, so an export can reconnect mid-run
            // without touching the request's lazily built engine.
            $report = $this->factory->make(reconnecting: $exports)
                ->tick($budget ?? (int) config('stardust.tick.budget'), $advisories, $exports);

            $run->fill([
                'rounds' => $report->rounds,
                'budget_seconds' => $report->budgetSeconds,
                'elapsed_seconds' => round($report->elapsedSeconds, 3),
                'stop_reason' => $report->stopReason->value,
            ]);

            if ($report->stopReason === TickStopReason::LOCK_CONTENDED) {
                Log::channel('stardust')->info('StarSystem tick skipped: another process holds the watcher pid file.');
            }
        } catch (Throwable $e) {
            $run->error = mb_substr($e::class.': '.$e->getMessage(), 0, 2000);

            report($e);
        } finally {
            $run->finished_at = now();
            $run->save();
        }

        return $run;
    }
}
