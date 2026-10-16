<?php

use App\Access\Ability;
use App\Http\Controllers\Admin\EntryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'site.member', 'verified'])
    ->prefix('admin/models/{model}/entries')
    ->name('admin.models.entries.')
    ->group(function () {
        Route::get('/', [EntryController::class, 'index'])->middleware('can:'.Ability::ENTRIES_VIEW)->name('index')->whereNumber('model');
        Route::get('create', [EntryController::class, 'create'])->middleware('can:'.Ability::ENTRIES_CREATE)->name('create')->whereNumber('model');
        Route::post('/', [EntryController::class, 'store'])->middleware('can:'.Ability::ENTRIES_CREATE)->name('store')->whereNumber('model');
        Route::get('trash', [EntryController::class, 'trash'])->middleware('can:'.Ability::ENTRIES_VIEW)->name('trash')->whereNumber('model');
        Route::get('{entry}', [EntryController::class, 'edit'])->middleware('can:'.Ability::ENTRIES_VIEW)->name('edit')->whereNumber(['model', 'entry']);
        Route::put('{entry}', [EntryController::class, 'update'])->middleware('can:'.Ability::ENTRIES_UPDATE)->name('update')->whereNumber(['model', 'entry']);
        Route::delete('{entry}', [EntryController::class, 'destroy'])->middleware('can:'.Ability::ENTRIES_DELETE)->name('destroy')->whereNumber(['model', 'entry']);
        Route::post('{entry}/trash', [EntryController::class, 'moveToTrash'])->middleware('can:'.Ability::ENTRIES_TRASH)->name('trash.store')->whereNumber(['model', 'entry']);
        Route::post('{entry}/restore', [EntryController::class, 'restore'])->middleware('can:'.Ability::ENTRIES_RESTORE)->name('restore')->whereNumber(['model', 'entry']);
    });
