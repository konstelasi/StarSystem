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
}
