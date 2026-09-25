<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BuilderServiceProvider extends ServiceProvider
{
    /**
     * Load the model builder's routes into the web group.
     */
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(base_path('routes/builder.php'));
    }
}
