<?php

use App\Http\Controllers\Settings\ConfirmEmailChangeController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'site.member'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // The link in the email that confirms a new address. Signed, so it can't
    // be altered, and throttled like the other links that carry a secret.
    Route::get('settings/profile/email/{user}/{hash}', ConfirmEmailChangeController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->whereNumber('user')
        ->name('profile.email.confirm');
});

Route::middleware(['auth', 'site.member', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::delete('settings/sessions', [SessionController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('sessions.destroy');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
