<?php

use App\Files\Http\FileApiController;
use App\Files\Http\MediaLibraryController;
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

Route::middleware(['web', 'auth', 'verified'])
    ->get('admin/files', MediaLibraryController::class)
    ->name('admin.files');

// The media library's JSON API, also used by <FilePicker>.
Route::middleware(['web', 'auth', 'verified'])
    ->prefix('admin/api/files')
    ->name('admin.api.files.')
    ->controller(FileApiController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::patch('{uuid}', 'update')->name('update');
        Route::delete('{uuid}', 'destroy')->name('destroy');
    });
