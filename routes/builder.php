<?php

use App\Access\Ability;
use App\Http\Controllers\Admin\ModelBuilderController;
use App\Http\Controllers\Admin\ModelController;
use App\Http\Controllers\Admin\ModelTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'site.member', 'verified'])
    ->prefix('admin/models')
    ->name('admin.models.')
    ->group(function () {
        Route::get('/', [ModelController::class, 'index'])->middleware('can:'.Ability::MODELS_VIEW)->name('index');
        Route::post('/', [ModelController::class, 'store'])->middleware('can:'.Ability::MODELS_CREATE)->name('store');
        Route::post('import', [ModelTransferController::class, 'import'])->middleware(['can:'.Ability::MODELS_IMPORT, 'throttle:10,1'])->name('import');
        Route::delete('{model}', [ModelController::class, 'destroy'])->middleware('can:'.Ability::MODELS_DELETE)->name('destroy')->whereNumber('model');

        // The single-model builder's old entry point, before the list existed.
        Route::redirect('builder', '/admin/models');

        // Opening the builder is for changing a model, so it needs the same
        // ability as saving.
        Route::middleware('can:'.Ability::MODELS_UPDATE.',model')->group(function () {
            Route::get('{model}/builder', [ModelBuilderController::class, 'show'])->name('builder')->whereNumber('model');
            Route::post('{model}/builder/preview', [ModelBuilderController::class, 'preview'])->name('builder.preview')->whereNumber('model');
            Route::post('{model}/builder/save', [ModelBuilderController::class, 'save'])->name('builder.save')->whereNumber('model');
            Route::post('{model}/builder/retry', [ModelBuilderController::class, 'retry'])->name('builder.retry')->whereNumber('model');
        });

        Route::get('{model}/export', [ModelTransferController::class, 'export'])->middleware('can:'.Ability::MODELS_EXPORT.',model')->name('export')->whereNumber('model');
        Route::post('{model}/duplicate', [ModelTransferController::class, 'duplicate'])->middleware('can:'.Ability::MODELS_CREATE)->name('duplicate')->whereNumber('model');
    });
