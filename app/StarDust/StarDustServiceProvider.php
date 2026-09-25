<?php

namespace App\StarDust;

use App\StarDust\Console\BootstrapCommand;
use App\StarDust\Console\DaemonCommand;
use App\StarDust\Console\TickCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use StarDust\StarDust;

class StarDustServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StarDustFactory::class);

        // Lazy on purpose: Config takes an open PDO, so building the engine
        // eagerly would open a second database connection on every request,
        // and shared hosts cap connections per account.
        $this->app->singleton(StarDust::class, fn (Application $app) => $app->make(StarDustFactory::class)->make());

        $this->app->singleton(StarDustService::class);
        $this->app->singleton(TickRunner::class);
        $this->app->singleton(TickPause::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BootstrapCommand::class,
                TickCommand::class,
                DaemonCommand::class,
            ]);
        }
    }
}
