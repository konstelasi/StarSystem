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
        Route::get('trash', [EntryController::class, 'trash'])->name('trash')->whereNumber('model');
        Route::get('{entry}', [EntryController::class, 'edit'])->name('edit')->whereNumber(['model', 'entry']);
        Route::put('{entry}', [EntryController::class, 'update'])->name('update')->whereNumber(['model', 'entry']);
        Route::post('{entry}/trash', [EntryController::class, 'moveToTrash'])->name('trash.store')->whereNumber(['model', 'entry']);
        Route::post('{entry}/restore', [EntryController::class, 'restore'])->name('restore')->whereNumber(['model', 'entry']);
    });
