<?php

namespace App\Hooks;

use Illuminate\Support\ServiceProvider;

class HooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Not scoped: modules add their callbacks once while the app boots,
        // and those must survive into every request the app serves.
        $this->app->singleton(HookRegistry::class);
    }
}
