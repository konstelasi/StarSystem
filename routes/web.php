<?php

use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\UpdateController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

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
