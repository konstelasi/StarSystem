<?php

use App\Http\Controllers\Admin\HealthController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('admin/health', HealthController::class)->name('admin.health');
});

require __DIR__.'/settings.php';
