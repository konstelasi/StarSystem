<?php

namespace App\Files;

use App\Files\Console\PurgeTrashCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A disk of its own, rooted outside public/, unless the install
        // defines one in config/filesystems.php.
        $disk = config()->string('files.disk');

        if (config("filesystems.disks.{$disk}") === null) {
            config(["filesystems.disks.{$disk}" => [
                'driver' => 'local',
                'root' => config()->string('files.root'),
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ]]);
        }

        // Scoped like CurrentSite, which it reads.
        $this->app->scoped(FileStore::class);
        $this->app->singleton(FileTypePolicy::class);
        $this->app->singleton(UploadLimits::class);
    }

    public function boot(): void
    {
        // Registered before routes/web.php, so a catch-all page route there
        // can never shadow /files.
        $this->loadRoutesFrom(base_path('routes/files.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTrashCommand::class]);

            // A closure, not Schedule::command(): that starts a child process
            // through proc_open, which many shared hosts disable.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->call(fn (TrashPurger $purger) => $purger->purge())
                    ->name('files:purge-trash')
                    ->dailyAt('03:17');
            });
        }
    }
}
