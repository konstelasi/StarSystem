<?php

use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\ModuleAssetController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Enabled modules' prebuilt front-end files. No session and no site: the
// same files serve every site.
Route::get('/_modules/{slug}/{path}', ModuleAssetController::class)
    ->where('path', '.*')
    ->name('modules.asset');

// Installing or enabling a module runs its PHP with full access to the
// install, so every page here needs the owner and a fresh password.
Route::middleware(['web', 'auth', 'verified', 'can:manage-modules', RequirePassword::class])
    ->prefix('admin/modules')
    ->name('admin.modules.')
    ->group(function () {
        Route::get('/', [ModuleController::class, 'index'])->name('index');
        Route::get('upload', [ModuleController::class, 'create'])->name('create');
        Route::post('/', [ModuleController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::post('{slug}/enable', [ModuleController::class, 'enable'])->name('enable');
        Route::post('{slug}/disable', [ModuleController::class, 'disable'])->name('disable');
        Route::post('{slug}/update', [ModuleController::class, 'update'])->name('update');
        Route::post('{slug}/dismiss', [ModuleController::class, 'dismiss'])->name('dismiss');
        Route::delete('{slug}', [ModuleController::class, 'destroy'])->name('destroy');
    });
