<?php

use App\Access\Ability;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Sites\CurrentSite;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// What visitors see until the site has a public front end.
Route::get('/', fn (CurrentSite $site) => Inertia::render('Home', [
    'siteName' => $site->get()->name,
]))->name('home');

Route::middleware(['auth', 'site.member', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('admin/health', HealthController::class)->middleware('can:'.Ability::HEALTH_VIEW)->name('admin.health');

    // Who can use the site and as what. Changing it asks for the password
    // again, like modules and updates.
    Route::middleware(RequirePassword::class)
        ->prefix('admin/users')
        ->name('admin.users.')
        ->controller(UserController::class)
        ->group(function () {
            Route::get('/', 'index')->middleware('can:'.Ability::USERS_VIEW)->name('index');
            Route::put('{member}', 'update')->middleware('can:'.Ability::USERS_UPDATE)->name('update')->whereNumber('member');
            Route::delete('{member}', 'destroy')->middleware('can:'.Ability::USERS_REMOVE)->name('destroy')->whereNumber('member');
        });

    // Updating replaces the program's files for every site, so it belongs to
    // the install owner alone.
    Route::controller(UpdateController::class)->prefix('admin/updates')->name('admin.updates')->middleware('can:manage-updates')->group(function () {
        Route::middleware(RequirePassword::class)->group(function () {
            Route::get('/', 'show');
            Route::post('start', 'start')->name('.start');
        });

        Route::post('check', 'check')->name('.check');
        Route::post('step', 'step')->name('.step');
        Route::post('retry', 'retry')->name('.retry');
    });
});

require __DIR__.'/settings.php';
