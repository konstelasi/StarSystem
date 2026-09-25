<?php

use App\Http\Controllers\Admin\ModelBuilderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('admin/models/builder', [ModelBuilderController::class, 'show'])->name('admin.models.builder');
});
