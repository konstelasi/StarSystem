<?php

namespace App\Providers;

use App\Install\Http\RedirectToInstaller;
use App\Install\Installation;
use App\StarDust\TickPause;
use App\Update\Updater;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

/**
 * Getting StarSystem onto a shared host whose owner has no shell, and
 * keeping it current there: the web installer and the updater.
 */
class DistributionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Installation::class);

        $this->app->bind(Updater::class, fn ($app) => new Updater(
            base_path(),
            storage_path('app/update'),
            $app->make(TickPause::class),
        ));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/install.php'));

        // Global, ahead of the web group, whose cookie middleware throws
        // before anything else can run while APP_KEY is empty.
        $this->app->make(HttpKernel::class)->prependMiddleware(RedirectToInstaller::class);
    }
}
