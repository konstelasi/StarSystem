<?php

use App\Access\Ability;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\InviteController;
use App\Http\Controllers\Admin\OwnershipController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TwoFactorRequirementController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserSuspensionController;
use App\Http\Controllers\InvitationController;
use App\Sites\CurrentSite;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// What visitors see until the site has a public front end.
Route::get('/', fn (CurrentSite $site) => Inertia::render('Home', [
    'siteName' => $site->get()->name,
]))->name('home');

// An invitation link. Open to anyone who holds it, so it is throttled, and
// outside `site.member` because the person is not a member yet.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'site.member', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('admin/health', HealthController::class)->middleware('can:'.Ability::HEALTH_VIEW)->name('admin.health');

    Route::get('admin/activity', ActivityController::class)->middleware('can:'.Ability::ACTIVITY_VIEW)->name('admin.activity');

    // Who can use the site and as what. Changing it asks for the password
    // again, like modules and updates.
    Route::middleware(RequirePassword::class)
        ->prefix('admin/users')
        ->name('admin.users.')
        ->controller(UserController::class)
        ->group(function () {
            Route::get('/', 'index')->middleware('can:'.Ability::USERS_VIEW)->name('index');
            Route::post('invites', [InviteController::class, 'store'])->middleware('can:'.Ability::USERS_INVITE)->name('invites.store');
            Route::post('invites/{invite}/resend', [InviteController::class, 'resend'])->middleware('can:'.Ability::USERS_INVITE)->name('invites.resend')->whereNumber('invite');
            Route::delete('invites/{invite}', [InviteController::class, 'destroy'])->middleware('can:'.Ability::USERS_INVITE)->name('invites.destroy')->whereNumber('invite');
            Route::post('{member}/suspend', [UserSuspensionController::class, 'store'])->middleware('can:'.Ability::USERS_SUSPEND)->name('suspend')->whereNumber('member');
            Route::delete('{member}/suspend', [UserSuspensionController::class, 'destroy'])->middleware('can:'.Ability::USERS_SUSPEND)->name('resume')->whereNumber('member');
            Route::post('ownership', OwnershipController::class)->middleware('can:transfer-ownership')->name('ownership');
            Route::put('{member}', 'update')->middleware('can:'.Ability::USERS_UPDATE)->name('update')->whereNumber('member');
            Route::delete('{member}', 'destroy')->middleware('can:'.Ability::USERS_REMOVE)->name('destroy')->whereNumber('member');
        });

    // The roles a site can give, behind the same password prompt.
    Route::middleware(RequirePassword::class)
        ->prefix('admin/roles')
        ->name('admin.roles.')
        ->controller(RoleController::class)
        ->group(function () {
            Route::get('/', 'index')->middleware('can:'.Ability::ROLES_VIEW)->name('index');
            Route::put('two-factor', TwoFactorRequirementController::class)->middleware('can:'.Ability::ROLES_UPDATE)->name('two-factor');
            Route::get('create', 'create')->middleware('can:'.Ability::ROLES_CREATE)->name('create');
            Route::post('/', 'store')->middleware('can:'.Ability::ROLES_CREATE)->name('store');
            Route::get('{role}', 'edit')->middleware('can:'.Ability::ROLES_VIEW)->name('edit');
            Route::put('{role}', 'update')->middleware('can:'.Ability::ROLES_UPDATE)->name('update');
            Route::delete('{role}', 'destroy')->middleware('can:'.Ability::ROLES_DELETE)->name('destroy');
            Route::post('{role}/duplicate', 'duplicate')->middleware('can:'.Ability::ROLES_CREATE)->name('duplicate');
        });

    // Updating replaces the program's files for every site, so it belongs to
    // the install owner alone.
    Route::controller(UpdateController::class)->prefix('admin/updates')->name('admin.updates')->middleware('can:manage-updates')->group(function () {
        Route::middleware(RequirePassword::class)->group(function () {
            Route::get('/', 'show');
            Route::post('start', 'start')->name('.start');
        });

        Route::post('check', 'check')->name('.check');
        Route::post('step', 'step')->name('.step');
        Route::post('retry', 'retry')->name('.retry');
    });
});

require __DIR__.'/settings.php';
