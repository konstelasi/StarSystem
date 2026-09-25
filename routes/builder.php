<?php

use App\Http\Controllers\Admin\ModelBuilderController;
use App\Http\Controllers\Admin\ModelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('admin/models')
    ->name('admin.models.')
    ->group(function () {
        Route::get('/', [ModelController::class, 'index'])->name('index');

        // The site's current single-model entry point, kept only until the
        // models list can create and open models itself.
        Route::get('builder', [ModelBuilderController::class, 'start'])->name('start');

        Route::get('{model}/builder', [ModelBuilderController::class, 'show'])->name('builder')->whereNumber('model');
        Route::post('{model}/builder/preview', [ModelBuilderController::class, 'preview'])->name('builder.preview')->whereNumber('model');
        Route::post('{model}/builder/save', [ModelBuilderController::class, 'save'])->name('builder.save')->whereNumber('model');
    });
