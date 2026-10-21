<?php

use App\Access\Ability;
use App\Http\Controllers\Admin\EntryController;
use Illuminate\Support\Facades\Route;

// Each route asks about the model in its URL, so a role that holds an ability
// for this model only still gets through here and nowhere else.
$onModel = fn (string $ability) => 'can:'.$ability.',model';

Route::middleware(['auth', 'site.member', 'verified'])
    ->prefix('admin/models/{model}/entries')
    ->name('admin.models.entries.')
    ->group(function () use ($onModel) {
        Route::get('/', [EntryController::class, 'index'])->middleware($onModel(Ability::ENTRIES_VIEW))->name('index')->whereNumber('model');
        Route::get('create', [EntryController::class, 'create'])->middleware($onModel(Ability::ENTRIES_CREATE))->name('create')->whereNumber('model');
        Route::post('/', [EntryController::class, 'store'])->middleware($onModel(Ability::ENTRIES_CREATE))->name('store')->whereNumber('model');
        Route::get('trash', [EntryController::class, 'trash'])->middleware($onModel(Ability::ENTRIES_VIEW))->name('trash')->whereNumber('model');
        Route::get('lookup', [EntryController::class, 'lookup'])->middleware($onModel(Ability::ENTRIES_VIEW))->name('lookup')->whereNumber('model');
        Route::get('{entry}', [EntryController::class, 'edit'])->middleware($onModel(Ability::ENTRIES_VIEW))->name('edit')->whereNumber(['model', 'entry']);
        Route::put('{entry}', [EntryController::class, 'update'])->middleware($onModel(Ability::ENTRIES_UPDATE))->name('update')->whereNumber(['model', 'entry']);
        Route::delete('{entry}', [EntryController::class, 'destroy'])->middleware($onModel(Ability::ENTRIES_DELETE))->name('destroy')->whereNumber(['model', 'entry']);
        Route::post('{entry}/trash', [EntryController::class, 'moveToTrash'])->middleware($onModel(Ability::ENTRIES_TRASH))->name('trash.store')->whereNumber(['model', 'entry']);
        Route::post('{entry}/restore', [EntryController::class, 'restore'])->middleware($onModel(Ability::ENTRIES_RESTORE))->name('restore')->whereNumber(['model', 'entry']);
    });
