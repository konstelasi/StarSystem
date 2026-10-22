<?php

use App\Frontend\Http\FrontendController;
use App\Http\Controllers\ThemeAssetController;
use App\Http\Middleware\ResolveSite;
use Illuminate\Support\Facades\Route;

// Loaded by FrontendServiceProvider.

// A theme's prebuilt files. No session, cookies or site: the same files
// serve every site, and the response stays cacheable.
Route::get('/_themes/{name}/{path}', ThemeAssetController::class)
    ->where('name', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->where('path', '.*')
    ->name('themes.asset');

// The public pages. Only the site is resolved: no session, cookies or CSRF,
// so each page is the same for every visitor and can be kept for the next.
// The fallback answers any other GET address, after every other route had
// its chance, and shows a published entry or the theme's 404 page.
Route::middleware(ResolveSite::class)->group(function () {
    Route::get('/', [FrontendController::class, 'home'])->name('home');
    Route::fallback([FrontendController::class, 'show']);
});
