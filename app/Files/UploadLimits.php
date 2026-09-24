<?php

namespace App\Files;

/**
 * The largest upload this install can take. PHP drops anything above
 * upload_max_filesize or post_max_size before Laravel sees it, and shared
 * hosts often set those far below files.max_size, so the effective limit is
 * the smallest of the three. The admin shows it, and names the setting to
 * raise, so "upload failed" becomes "your host allows 8 MB".
 */
class UploadLimits
{
    /**
     * The effective per-file limit in bytes.
     */
    public function maxFileBytes(): int
    {
        return min(array_values($this->limits()));
    }

    /**
     * Which setting is the effective limit: files.max_size,
     * upload_max_filesize or post_max_size.
     */
    public function bindingSetting(): string
    {
        $limits = $this->limits();

        return (string) array_search(min($limits), $limits, true);
    }

    /**
     * @return array{maxFileBytes: int, maxFileSize: string, bindingSetting: string, configured: int, uploadMaxFilesize: int, postMaxSize: int, canDetectTypes: bool}
     */
    public function toArray(): array
    {
        $limits = $this->limits();

        return [
            'maxFileBytes' => $this->maxFileBytes(),
            'maxFileSize' => self::human($this->maxFileBytes()),
            'bindingSetting' => $this->bindingSetting(),
            'configured' => $limits['files.max_size'],
            'uploadMaxFilesize' => $limits['upload_max_filesize'],
            'postMaxSize' => $limits['post_max_size'],
            'canDetectTypes' => extension_loaded('fileinfo'),
        ];
    }

    /**
     * Every limit in bytes, with "no limit" as PHP_INT_MAX so min() works.
     *
     * @return array{'files.max_size': int, upload_max_filesize: int, post_max_size: int}
     */
    private function limits(): array
    {
        $unlimited = fn (int $bytes) => $bytes > 0 ? $bytes : PHP_INT_MAX;

        return [
            'files.max_size' => $unlimited(config()->integer('files.max_size')),
            'upload_max_filesize' => $unlimited(self::parseIniSize($this->ini('upload_max_filesize'))),
            'post_max_size' => $unlimited(self::parseIniSize($this->ini('post_max_size'))),
        ];
    }

    protected function ini(string $key): string|false
    {
        return ini_get($key);
    }

    /**
     * Parses php.ini shorthand ("8M", "512K", "1G") to bytes. 0 means no
     * limit, as it does in php.ini.
     */
    public static function parseIniSize(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/^(\d+)\s*([kmg]?)b?$/i', $value, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    /**
     * "8 MB", "512 KB". Written by hand because Number::fileSize needs intl,
     * which some shared hosts don't have.
     */
    public static function human(int $bytes): string
    {
        if ($bytes === PHP_INT_MAX) {
            return 'no limit';
        }

        $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        $rounded = $unit === 0 || $size >= 10 ? (string) round($size) : rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.');

        return $rounded.' '.$units[$unit];
    }
}
