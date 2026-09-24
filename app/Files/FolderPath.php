<?php

namespace App\Files;

/**
 * Virtual folder paths such as "photos/2026". The root is "". Folders only
 * exist as a path on file rows, so they never reach the filesystem, but
 * they are normalised so "Photos/", "/Photos" and "Photos" are one folder
 * and ".." can't sneak into anything that builds a path from them later.
 */
final class FolderPath
{
    public const MAX_LENGTH = 255;

    public const MAX_DEPTH = 10;

    /**
     * @throws FileRejected when the path has an empty, dot or unsafe segment
     */
    public static function normalize(?string $path): string
    {
        $path = trim(str_replace('\\', '/', (string) $path));
        $segments = array_values(array_filter(
            array_map('trim', explode('/', $path)),
            fn (string $segment) => $segment !== '',
        ));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || ! preg_match('/^[\pL\pN][\pL\pN _.,()&+\'-]*$/u', $segment)) {
                throw FileRejected::because("\"{$segment}\" can't be used as a folder name. Use letters, numbers, spaces, dots, dashes and brackets.");
            }
        }

        if (count($segments) > self::MAX_DEPTH) {
            throw FileRejected::because('Folders can be nested at most '.self::MAX_DEPTH.' levels deep.');
        }

        $normalized = implode('/', $segments);

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw FileRejected::because('This folder path is too long.');
        }

        return $normalized;
    }
}
