<?php

namespace App\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AbilityRegistry::class);
        $this->app->singleton(SiteAccess::class);
    }

    public function boot(): void
    {
        // One gate per ability, StarSystem's own and those of the modules
        // running now, which are registered before any provider boots.
        $this->app->make(AbilityRegistry::class)->defineGates();

        // A role in a URL is one this site can use: a built-in role or its
        // own. Another site's role is a 404.
        Route::bind('role', fn (string $value) => Role::available()->findOrFail($value));

        // Installing a module runs arbitrary PHP, and an update replaces the
        // program's files for every site, so both belong to the install owner
        // alone and not to any site's role.
        Gate::define('manage-modules', fn (User $user) => $user->is_install_owner);
        Gate::define('manage-updates', fn (User $user) => $user->is_install_owner);

        // Handing the ownership over is the owner's to do, since it moves
        // those two powers to someone else.
        Gate::define('transfer-ownership', fn (User $user) => $user->is_install_owner);
    }
}
