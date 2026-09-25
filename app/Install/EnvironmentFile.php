<?php

namespace App\Install;

use InvalidArgumentException;
use RuntimeException;

/**
 * Edits the .env file the way a person would: the keys it's given are
 * replaced in place, everything else (comments, order, keys it doesn't
 * know) is left alone, and a missing file starts from .env.example.
 *
 * Values are quoted so phpdotenv reads back exactly what was written.
 * A database password is typed by the consumer and may hold any of the
 * characters dotenv treats as syntax, and a password that silently loses
 * its "#" tail is a support ticket nobody can diagnose.
 */
class EnvironmentFile
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $template = null,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Writable now, or creatable in a writable folder.
     */
    public function writable(): bool
    {
        return $this->exists() ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        foreach ($this->lines((string) file_get_contents($this->path)) as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)$/', $line, $m) === 1) {
                $value = trim($m[1]);

                return $this->unquote($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $contents = $this->exists()
            ? (string) file_get_contents($this->path)
            : ($this->template !== null && is_file($this->template) ? (string) file_get_contents($this->template) : '');

        $lines = $this->lines($contents);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->encode($key, $value);
            $pattern = '/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/';
            $found = false;

            foreach ($lines as $i => $existing) {
                if (preg_match($pattern, $existing) === 1) {
                    $lines[$i] = $line;
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $lines[] = $line;
            }
        }

        $this->write(rtrim(implode("\n", $lines), "\n")."\n");
    }

    public static function encode(string $key, string|int|bool|null $value): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("[{$key}] is not a valid environment variable name.");
        }

        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException("The value for [{$key}] can't span more than one line.");
        }

        if (preg_match('/^[A-Za-z0-9_.,:;\/@+=%-]*$/', $value) === 1) {
            return $value;
        }

        // Double quotes with \, " and $ escaped is the one dotenv form that
        // round-trips every single-line string.
        return '"'.addcslashes($value, '\\"$').'"';
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function lines(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        return preg_split('/\r\n|\n|\r/', rtrim($contents, "\r\n")) ?: [];
    }

    /**
     * Write to a temporary file and rename it over the old one, so a
     * request that dies halfway never leaves a truncated .env behind.
     */
    private function write(string $contents): void
    {
        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Couldn't write {$this->path}.");
        }

        @chmod($temporary, 0640);

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            // Some hosts refuse renames over an existing file; writing in
            // place is the fallback.
            if (@file_put_contents($this->path, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Couldn't write {$this->path}.");
            }
        }
    }
}
