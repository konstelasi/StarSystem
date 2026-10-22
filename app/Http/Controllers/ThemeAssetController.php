<?php

namespace App\Http\Controllers;

use App\Frontend\ThemeManager;
use App\Http\AssetTypes;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves files from a theme's assets folder. The themes folder sits outside
 * public/, so this is the only way a theme's styles, scripts, images and
 * fonts reach the browser. It serves nothing from the theme but its assets
 * folder, and nothing there but the types AssetTypes lists, so the theme's
 * templates and manifest are never sent.
 */
class ThemeAssetController extends Controller
{
    public function __invoke(ThemeManager $themes, string $name, string $path): BinaryFileResponse
    {
        $theme = $themes->find($name);
        $root = $theme ? realpath($theme->assetsPath()) : false;
        $type = AssetTypes::forPath($path);

        abort_if($root === false || $type === null || str_contains($path, "\0"), 404);

        // realpath() resolves ../ and symlinks, so this also stops paths
        // that lead out of the assets folder.
        $file = realpath($root.DIRECTORY_SEPARATOR.$path);

        abort_unless($file !== false && is_file($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($file, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
