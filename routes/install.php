<?php

use App\Install\Http\HandleInstallerRequests;
use App\Install\Http\InstallController;
use App\Install\Http\InstallerGate;
use Illuminate\Support\Facades\Route;

// Outside the web group on purpose: no cookie encryption, session or CSRF,
// because none of them work before APP_KEY and the database exist. The
// gate 404s every route here once StarSystem is installed.
Route::middleware([InstallerGate::class, HandleInstallerRequests::class])
    ->prefix('install')
    ->controller(InstallController::class)
    ->group(function () {
        Route::get('/', 'requirements')->name('install');

        Route::get('database', 'database')->name('install.database');
        Route::post('database', 'saveDatabase')->name('install.database.store');

        Route::get('setup', 'setup')->name('install.setup');
        Route::post('setup/migrate', 'migrate')->name('install.setup.migrate');
        Route::post('setup/stardust', 'stardust')->name('install.setup.stardust');

        Route::get('admin', 'admin')->name('install.admin');
        Route::post('admin', 'finish')->name('install.admin.store');
    });
