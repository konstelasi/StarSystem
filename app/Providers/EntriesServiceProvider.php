<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EntriesServiceProvider extends ServiceProvider
{
    /**
     * Load the entries admin's routes into the web group.
     */
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(base_path('routes/entries.php'));
    }
}
