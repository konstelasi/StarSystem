<?php

namespace App\Frontend;

/**
 * A theme folder that passed its checks: templates under views/, files
 * for the browser under assets/ and a theme.json that describes it.
 */
final class Theme
{
    /**
     * @param  string  $name  The folder name, which is also the theme's identity in the database and in URLs.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly ThemeManifest $manifest,
    ) {}

    public function viewsPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'views';
    }

    public function assetsPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'assets';
    }

    /**
     * The address of a file under assets/. The version on the end makes a
     * browser fetch it again after the theme is updated.
     */
    public function assetUrl(string $file): string
    {
        return url('_themes/'.$this->name.'/'.ltrim($file, '/')).'?v='.rawurlencode($this->manifest->version);
    }

    /**
     * Whether views/ holds the template, named as Blade names it, such as
     * "entry" or "fields.text".
     */
    public function has(string $template): bool
    {
        return $this->templatePath($template) !== null;
    }

    /**
     * The file of a template in views/, or null when there is none. The name
     * comes from code, never from the request, and anything but letters,
     * digits, dashes, underscores and dots is answered with null.
     */
    public function templatePath(string $template): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $template)) {
            return null;
        }

        $file = $this->viewsPath().DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $template).'.blade.php';

        return is_file($file) ? $file : null;
    }
}
