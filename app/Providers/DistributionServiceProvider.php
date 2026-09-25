<?php

namespace App\Providers;

use App\Install\Http\RedirectToInstaller;
use App\Install\Installation;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

/**
 * Getting StarSystem onto a shared host whose owner has no shell: the web
 * installer.
 */
class DistributionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Installation::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/install.php'));

        // Global, ahead of the web group, whose cookie middleware throws
        // before anything else can run while APP_KEY is empty.
        $this->app->make(HttpKernel::class)->prependMiddleware(RedirectToInstaller::class);
    }
}
