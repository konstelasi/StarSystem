<?php

namespace App\Frontend;

use Illuminate\Support\ServiceProvider;

class FrontendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HtmlCleaner::class);

        $this->app->singleton(ThemeManager::class, fn () => new ThemeManager(
            rtrim(config()->string('starsystem.themes.path'), '/\\'),
            config()->string('starsystem.themes.default'),
        ));
    }

    public function boot(): void
    {
        // Registered before routes/web.php, so a catch-all page route there
        // can never shadow /_themes.
        $this->loadRoutesFrom(base_path('routes/frontend.php'));
    }
}
