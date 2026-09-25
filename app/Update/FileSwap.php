<?php

namespace App\Update;

/**
 * Moves an unpacked release into place and back out again, by renaming.
 *
 * A rename is instant and all-or-nothing, so vendor/ is never half old and
 * half new. Every method works out what is left to do from what is on
 * disk, so a request that died halfway is finished by running it again.
 *
 * Only what the release ships is touched: storage/ never, and public/ one
 * entry at a time, so files the host or owner put there stay.
 */
class FileSwap
{
    public function __construct(
        private readonly string $root,
        private readonly string $staging,
        private readonly string $backup,
        private readonly string $discard,
    ) {}

    /**
     * The paths, relative to the install, that the release replaces.
     *
     * @return list<string>
     */
    public static function units(string $staging): array
    {
        $units = [];

        foreach (self::entries($staging) as $entry) {
            if ($entry === 'storage') {
                continue;
            }

            if ($entry === 'public') {
                foreach (self::entries($staging.'/public') as $public) {
                    $units[] = 'public/'.$public;
                }

                continue;
            }

            $units[] = $entry;
        }

        sort($units);

        return $units;
    }

    /**
     * Of $units, the ones with no live counterpart yet, so restore() knows
     * to take them away again rather than expect a backup to put back.
     *
     * @param  list<string>  $units
     * @return list<string>
     */
    public function additions(array $units): array
    {
        return array_values(array_filter($units, fn (string $unit) => ! $this->exists($this->paths($unit)[0])));
    }

    /**
     * @param  list<string>  $units
     */
    public function apply(array $units): void
    {
        foreach ($units as $unit) {
            [$live, $staged, $backup] = $this->paths($unit);

            if (! $this->exists($staged)) {
                continue;
            }

            if ($this->exists($live)) {
                if ($this->exists($backup)) {
                    throw new UpdateException("Can't replace [{$unit}]: a backup of it already exists.");
                }

                $this->move($live, $backup);
            }

            $this->move($staged, $live);
        }
    }

    /**
     * Undoes apply(). $added must be the value additions() returned before
     * apply() ran, so an added unit is known to have no backup to restore
     * even after this runs once and the evidence for that is gone.
     *
     * Every unit's move is checked against what's still on disk before it
     * runs, so calling this again after it partly succeeded, or after it
     * fully succeeded, only finishes or repeats no work.
     *
     * @param  list<string>  $units
     * @param  list<string>  $added
     */
    public function restore(array $units, array $added = []): void
    {
        $added = array_flip($added);

        foreach (array_reverse($units) as $unit) {
            [$live, $staged, $backup] = $this->paths($unit);

            if (isset($added[$unit])) {
                // Never had a live counterpart, so putting it back means
                // only taking it away again, from wherever it ended up.
                if (! $this->exists($staged) && $this->exists($live)) {
                    $this->move($live, $this->discard.'/'.$unit);
                }

                continue;
            }

            // Not swapped in yet, unless it died between the two renames.
            if ($this->exists($staged)) {
                if ($this->exists($backup) && ! $this->exists($live)) {
                    $this->move($backup, $live);
                }

                continue;
            }

            // No backup left means this replacement is already restored,
            // by an earlier call: what's live now must be left alone.
            if ($this->exists($backup)) {
                if ($this->exists($live)) {
                    $this->move($live, $this->discard.'/'.$unit);
                }

                $this->move($backup, $live);
            }
        }
    }

    /**
     * @return array{string, string, string}
     */
    private function paths(string $unit): array
    {
        return [$this->root.'/'.$unit, $this->staging.'/'.$unit, $this->backup.'/'.$unit];
    }

    private function move(string $from, string $to): void
    {
        if (! is_dir(dirname($to))) {
            mkdir(dirname($to), 0775, true);
        }

        if ($this->exists($to)) {
            Files::remove($to);
        }

        if (! @rename($from, $to)) {
            throw new UpdateException("Couldn't move [{$from}] to [{$to}]. Check the folder permissions.");
        }
    }

    private function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }
}
