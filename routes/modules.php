<?php

use App\Http\Controllers\ModuleAssetController;
use Illuminate\Support\Facades\Route;

// Enabled modules' prebuilt front-end files. No session and no site: the
// same files serve every site.
Route::get('/_modules/{slug}/{path}', ModuleAssetController::class)
    ->where('path', '.*')
    ->name('modules.asset');
