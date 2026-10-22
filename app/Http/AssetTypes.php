<?php

namespace App\Http;

/**
 * The file types that a theme or a module may send to the browser from its
 * assets folder, by extension. Their folders sit outside public/, so a
 * controller is the only way these files are served, and it serves nothing
 * that isn't listed here.
 */
final class AssetTypes
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

    /**
     * The content type for a path, or null when its extension isn't served.
     */
    public static function forPath(string $path): ?string
    {
        return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }
}
