<?php

namespace App\Frontend;

use App\Models\Site;
use App\Modules\VersionConstraint;
use LogicException;

/**
 * The themes in the themes folder. A folder is a theme only when its
 * theme.json is valid and asks for a StarSystem version that is running.
 * A folder that fails is left out and reported, and never loaded.
 */
class ThemeManager
{
    private const FOLDER = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @var array{themes: array<string, Theme>, problems: array<string, string>}|null
     */
    private ?array $scan = null;

    public function __construct(
        private readonly string $root,
        private readonly string $default,
    ) {}

    /**
     * @return array<string, Theme> By folder name.
     */
    public function installed(): array
    {
        return $this->scan()['themes'];
    }

    /**
     * Why each folder that isn't a usable theme was left out, in a sentence
     * for the admin, by folder name.
     *
     * @return array<string, string>
     */
    public function problems(): array
    {
        return $this->scan()['problems'];
    }

    public function find(string $name): ?Theme
    {
        return $this->installed()[$name] ?? null;
    }

    /**
     * The theme the site shows: the one it chose, else the default one. A
     * chosen theme that was since deleted or broke gives the default, so a
     * site never goes blank over a folder.
     *
     * @throws LogicException When there is no default theme either.
     */
    public function active(Site $site): Theme
    {
        $chosen = $site->theme !== null && $site->theme !== '' ? $this->find($site->theme) : null;
        $theme = $chosen ?? $this->find($this->default);

        if ($theme === null) {
            $why = $this->problems()[$this->default] ?? 'There is no such folder.';

            throw new LogicException("The default theme folder [{$this->default}] in [{$this->root}] can't be used. {$why}");
        }

        return $theme;
    }

    /**
     * @return array{themes: array<string, Theme>, problems: array<string, string>}
     */
    private function scan(): array
    {
        if ($this->scan !== null) {
            return $this->scan;
        }

        $themes = [];
        $problems = [];
        $core = config()->string('modules.core_version');

        foreach (is_dir($this->root) ? (scandir($this->root) ?: []) : [] as $entry) {
            $path = $this->root.DIRECTORY_SEPARATOR.$entry;

            // Dotfiles such as .gitkeep, and anything that isn't a folder.
            if (str_starts_with($entry, '.') || ! is_dir($path)) {
                continue;
            }

            if (! preg_match(self::FOLDER, $entry) || strlen($entry) > 64) {
                $problems[$entry] = 'A theme folder is named in lowercase letters, digits and dashes, like "my-theme".';

                continue;
            }

            try {
                $manifest = ThemeManifest::load($path);
            } catch (InvalidThemeManifest $e) {
                $problems[$entry] = $e->getMessage();

                continue;
            }

            if (! VersionConstraint::satisfies($core, $manifest->requires)) {
                $problems[$entry] = "{$manifest->name} needs StarSystem {$manifest->requires}, and this is {$core}.";

                continue;
            }

            $themes[$entry] = new Theme($entry, $path, $manifest);
        }

        return $this->scan = ['themes' => $themes, 'problems' => $problems];
    }
}
