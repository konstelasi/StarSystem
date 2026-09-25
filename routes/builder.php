<?php

use App\Http\Controllers\Admin\ModelBuilderController;
use App\Http\Controllers\Admin\ModelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('admin/models')
    ->name('admin.models.')
    ->group(function () {
        Route::get('/', [ModelController::class, 'index'])->name('index');
        Route::post('/', [ModelController::class, 'store'])->name('store');

        // The single-model builder's old entry point, before the list existed.
        Route::redirect('builder', '/admin/models');

        Route::get('{model}/builder', [ModelBuilderController::class, 'show'])->name('builder')->whereNumber('model');
        Route::post('{model}/builder/preview', [ModelBuilderController::class, 'preview'])->name('builder.preview')->whereNumber('model');
        Route::post('{model}/builder/save', [ModelBuilderController::class, 'save'])->name('builder.save')->whereNumber('model');
    });
