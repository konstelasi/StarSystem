<?php

use App\Http\Controllers\System\TickController;
use Illuminate\Support\Facades\Route;

// Install-wide endpoints: no session, no site, no CSRF.
Route::match(['get', 'post'], '/_system/tick', TickController::class)
    ->middleware('throttle:6,1')
    ->name('system.tick');
