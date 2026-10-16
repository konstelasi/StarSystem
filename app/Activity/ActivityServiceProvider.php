<?php

namespace App\Activity;

use App\Activity\Console\PruneActivityCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ActivityServiceProvider extends ServiceProvider
{
    /**
     * Log sign-ins and two-factor changes, and delete old rows daily.
     */
    public function boot(): void
    {
        Event::subscribe(RecordAuthEvents::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneActivityCommand::class]);

            // A closure, not Schedule::command(): that starts a child process
            // through proc_open, which many shared hosts disable.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->call(fn (ActivityPruner $pruner) => $pruner->prune())
                    ->name('activity:prune')
                    ->dailyAt('03:47');
            });
        }
    }
}
