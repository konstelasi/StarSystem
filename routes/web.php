<?php

use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\UpdateController;
use App\Sites\CurrentSite;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// What visitors see until the site has a public front end.
Route::get('/', fn (CurrentSite $site) => Inertia::render('Home', [
    'siteName' => $site->get()->name,
]))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('admin/health', HealthController::class)->name('admin.health');

    Route::controller(UpdateController::class)->prefix('admin/updates')->name('admin.updates')->group(function () {
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
