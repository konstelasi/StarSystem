<?php

namespace App\StarDust\Console;

use App\StarDust\TickRunner;
use DomainException;
use Illuminate\Console\Command;

class TickCommand extends Command
{
    protected $signature = 'stardust:tick
        {--budget= : Seconds to run (defaults to config stardust.tick.budget)}
        {--advisories : Force the cardinality and spread samples now}
        {--trigger=manual : Recorded on the run: cron, url or manual}';

    protected $description = 'Run one budgeted StarDust tick (shared profile)';

    public function handle(TickRunner $runner): int
    {
        $trigger = in_array($this->option('trigger'), TickRunner::TRIGGERS, true) ? $this->option('trigger') : 'manual';
        $budget = $this->option('budget') !== null ? (int) $this->option('budget') : null;

        try {
            $run = $runner->run($trigger, $budget, (bool) $this->option('advisories'));
        } catch (DomainException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($run->stop_reason === TickRunner::STOP_PAUSED) {
            $this->components->warn('Tick skipped: background work is paused while StarSystem updates.');

            return self::SUCCESS;
        }

        if ($run->error !== null) {
            $this->components->error("Tick failed: {$run->error}");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Tick complete: %d round(s) in %.1fs (budget %ds), stopped: %s.',
            $run->rounds,
            $run->elapsed_seconds,
            $run->budget_seconds,
            $run->stop_reason,
        ));

        return self::SUCCESS;
    }
}
