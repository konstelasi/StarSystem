<?php

use App\Http\Middleware\EnsureSiteMember;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveSite;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/system.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // laravel_maintenance is the updater's maintenance bypass. It's
        // read before the web group decrypts anything, so it stays plain;
        // it carries its own signature.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'laravel_maintenance']);

        $middleware->alias(['site.member' => EnsureSiteMember::class]);

        $middleware->web(prepend: [
            ResolveSite::class,
        ], append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A signed-in account that follows a link its role doesn't allow gets a
        // page inside the admin, not the framework's bare error screen.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() === 403 && ! $request->expectsJson() && $request->user() !== null) {
                return Inertia::render('errors/Forbidden')->toResponse($request)->setStatusCode(403);
            }

            return $response;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
