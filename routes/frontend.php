<?php

use App\Http\Controllers\ThemeAssetController;
use Illuminate\Support\Facades\Route;

// Loaded by FrontendServiceProvider.

// A theme's prebuilt files. No session, cookies or site: the same files
// serve every site, and the response stays cacheable.
Route::get('/_themes/{name}/{path}', ThemeAssetController::class)
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->where('path', '.*')
    ->name('themes.asset');
