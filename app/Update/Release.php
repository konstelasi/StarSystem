<?php

namespace App\Update;

/**
 * One published release, as described by its release.json.
 */
final readonly class Release
{
    public function __construct(
        public string $version,
        public string $zip,
        public string $sha256,
        public ?string $publishedAt = null,
    ) {}

    /**
     * Read a release.json. A relative zip URL is resolved against the
     * feed's own URL, and an https feed can't point at a plain http zip.
     */
    public static function fromFeed(mixed $data, string $feedUrl): self
    {
        if (! is_array($data)) {
            throw new UpdateException('The update feed isn\'t a release description.');
        }

        $version = $data['version'] ?? null;
        $zip = $data['zip'] ?? null;
        $sha256 = $data['sha256'] ?? null;
        $publishedAt = $data['published_at'] ?? null;

        if (! is_string($version) || ! self::isVersion($version)) {
            throw new UpdateException('The update feed has no valid version number.');
        }

        if (! is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new UpdateException('The update feed has no valid checksum.');
        }

        if (! is_string($zip) || $zip === '') {
            throw new UpdateException('The update feed doesn\'t say where to download the release.');
        }

        $zip = self::resolve($zip, $feedUrl);
        $scheme = parse_url($zip, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true) || ($scheme === 'http' && parse_url($feedUrl, PHP_URL_SCHEME) === 'https')) {
            throw new UpdateException('The update feed points at a download address StarSystem won\'t use.');
        }

        return new self($version, $zip, $sha256, is_string($publishedAt) ? $publishedAt : null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['version'],
            (string) $data['zip'],
            (string) $data['sha256'],
            isset($data['published_at']) ? (string) $data['published_at'] : null,
        );
    }

    /**
     * @return array{version: string, zip: string, sha256: string, published_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'zip' => $this->zip,
            'sha256' => $this->sha256,
            'published_at' => $this->publishedAt,
        ];
    }

    public function newerThan(string $version): bool
    {
        return version_compare($this->version, $version, '>');
    }

    public static function isVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function resolve(string $url, string $base): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $origin = parse_url($base, PHP_URL_SCHEME).'://'.parse_url($base, PHP_URL_HOST);
            $port = parse_url($base, PHP_URL_PORT);

            return $origin.($port !== null ? ':'.$port : '').$url;
        }

        $path = (string) strtok($base, '?#');

        return substr($path, 0, (int) strrpos($path, '/') + 1).$url;
    }
}
