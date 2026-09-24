<?php

namespace App\Http\Controllers;

use App\Modules\ModuleLoader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves files from an enabled module's assets folder. The modules folder
 * sits outside public/, so this is the only way a module's scripts and
 * styles reach the browser, and it never serves anything but the asset
 * types below.
 */
class ModuleAssetController extends Controller
{
    private const TYPES = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'css' => 'text/css',
        'map' => 'application/json',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function __invoke(ModuleLoader $loader, string $slug, string $path): BinaryFileResponse
    {
        $module = $loader->loaded()[$slug] ?? null;
        $root = $module?->assetsPath() ? realpath($module->assetsPath()) : false;
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        // realpath() resolves ../ and symlinks, so this also stops paths
        // that lead out of the assets folder.
        abort_unless($root && $file && $type && is_file($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($file, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
