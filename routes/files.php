<?php

use App\Files\Http\ServeFileController;
use App\Http\Middleware\ResolveSite;
use Illuminate\Support\Facades\Route;

// Loaded by FilesServiceProvider.

// Public uploads. Only the site is resolved: no session, cookies or CSRF,
// so each request stays cheap and the response stays cacheable.
Route::get('/files/{uuid}/{name?}', ServeFileController::class)
    ->middleware(ResolveSite::class)
    ->where('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->name('files.show');
