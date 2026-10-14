<?php

namespace App\Providers;

use App\Entries\Console\PurgeTrashCommand;
use App\Entries\TrashPurger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EntriesServiceProvider extends ServiceProvider
{
    /**
     * Load the entries admin's routes into the web group, and empty the
     * trash daily.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTrashCommand::class]);

            // A closure, not Schedule::command(): that starts a child process
            // through proc_open, which many shared hosts disable.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->call(fn (TrashPurger $purger) => $purger->purge())
                    ->name('entries:purge-trash')
                    ->dailyAt('03:37');
            });
        }

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(base_path('routes/entries.php'));
        }
    }
}
