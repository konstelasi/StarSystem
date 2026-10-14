<?php

use App\Http\Controllers\Admin\EntryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('admin/models/{model}/entries')
    ->name('admin.models.entries.')
    ->group(function () {
        Route::get('/', [EntryController::class, 'index'])->name('index')->whereNumber('model');
        Route::get('create', [EntryController::class, 'create'])->name('create')->whereNumber('model');
        Route::post('/', [EntryController::class, 'store'])->name('store')->whereNumber('model');
    });
