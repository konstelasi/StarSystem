<?php

namespace App\Update;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The newest published release, from the release.json the release
 * workflow publishes. The answer is cached, so the admin page doesn't
 * reach out on every visit.
 *
 * A feed is only believed when its signature checks out against the
 * release key, because its checksum would otherwise come from the same
 * place as the zip it vouches for.
 *
 * A signature says who made a feed, not that it is current. So the newest
 * signed feed seen so far is kept in a file, and a feed older than that is
 * answered with the newer release and a warning. The file sits outside the
 * update's work folder, which every update empties, and outside the cache,
 * which can be flushed.
 */
class ReleaseFeed
{
    /**
     * Not the key earlier versions cached under, whose answers were never
     * checked and so mustn't be read as if they had been.
     */
    public const CACHE_KEY = 'starsystem.updates.feed.signed';

    public const CACHE_HOURS = 12;

    public function __construct(private readonly string $recordPath) {}

    public function url(): ?string
    {
        $url = config('starsystem.updates.feed');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array{release: ?Release, error: ?string, warning: ?string, checkedAt: ?string}
     */
    public function latest(bool $fresh = false): array
    {
        $cached = $fresh ? null : Cache::get(self::CACHE_KEY);

        if (! is_array($cached)) {
            $cached = $this->fetch();
            Cache::put(self::CACHE_KEY, $cached, CarbonImmutable::now()->addHours(self::CACHE_HOURS));
        }

        return [
            'release' => is_array($cached['release'] ?? null) ? Release::fromArray($cached['release']) : null,
            'error' => $cached['error'] ?? null,
            'warning' => $cached['warning'] ?? null,
            'checkedAt' => $cached['checkedAt'] ?? null,
        ];
    }

    /**
     * Only arrays go into the cache, so a cached answer still reads after
     * an update has changed the classes.
     *
     * @return array{release: ?array<string, mixed>, error: ?string, warning: ?string, checkedAt: string}
     */
    private function fetch(): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $url = $this->url();

        if ($url === null) {
            return ['release' => null, 'error' => 'Update checks are turned off on this install.', 'warning' => null, 'checkedAt' => $now];
        }

        try {
            $response = Http::timeout(15)->connectTimeout(10)->acceptJson()->get($url)->throw();
            $feed = $response->json();

            $this->assertSigned($feed);

            [$release, $warning] = $this->remember($feed, Release::fromFeed($feed, $url), $url);

            return ['release' => $release->toArray(), 'error' => null, 'warning' => $warning, 'checkedAt' => $now];
        } catch (UpdateException $e) {
            return ['release' => null, 'error' => $e->getMessage(), 'warning' => null, 'checkedAt' => $now];
        } catch (Throwable $e) {
            Log::warning('StarSystem update check failed.', ['exception' => $e]);

            return ['release' => null, 'error' => 'StarSystem couldn\'t reach the update feed. Try again later.', 'warning' => null, 'checkedAt' => $now];
        }
    }

    /**
     * A feed that isn't an array is left for Release::fromFeed to describe.
     */
    private function assertSigned(mixed $feed): void
    {
        $key = config('starsystem.updates.public_key');

        if (! is_string($key) || $key === '') {
            throw new UpdateException('This install has no release key, so it can\'t tell a real update feed from a forged one.');
        }

        if (is_array($feed) && ! FeedSignature::verify($feed, $key)) {
            throw new UpdateException('The update feed isn\'t signed with StarSystem\'s release key, so it was ignored. Nothing was changed.');
        }
    }

    /**
     * Compare a verified feed with the newest one seen before, and keep it
     * if it is newer. Trouble with the record is logged and never costs the
     * check: the live release is answered without a warning.
     *
     * @return array{Release, ?string} The release to offer, and a warning when the feed is out of date.
     */
    private function remember(mixed $feed, Release $live, string $url): array
    {
        if (! is_array($feed)) {
            return [$live, null];
        }

        try {
            return $this->locked(fn () => $this->compare($feed, $live, $url)) ?? [$live, null];
        } catch (Throwable $e) {
            Log::warning('StarSystem couldn\'t keep its record of the newest release.', ['exception' => $e]);

            return [$live, null];
        }
    }

    /**
     * @param  array<string, mixed>  $feed
     * @return array{Release, ?string}
     */
    private function compare(array $feed, Release $live, string $url): array
    {
        $seen = $this->seen($url);

        if ($seen === null || $live->newerThan($seen->version)) {
            $this->write($feed, $url);

            return [$live, null];
        }

        if ($seen->newerThan($live->version)) {
            return [$seen, "The update feed offers {$live->version}, which is older than {$seen->version}, a release this install has already seen. Showing {$seen->version} instead."];
        }

        return [$live, null];
    }

    /**
     * The release in the record, or null when there is none worth trusting:
     * no file, a file that isn't ours, one made for another feed, or one
     * that no longer verifies against the current key.
     */
    private function seen(string $url): ?Release
    {
        $json = @file_get_contents($this->recordPath);
        $record = $json === false ? null : json_decode($json, true);

        if (! is_array($record) || ($record['feed'] ?? null) !== $url || ! is_array($record['signed'] ?? null)) {
            return null;
        }

        try {
            $this->assertSigned($record['signed']);

            return Release::fromFeed($record['signed'], $url);
        } catch (UpdateException) {
            return null;
        }
    }

    /**
     * Whole or not at all, as state.json is written, so a reader never sees
     * half a record.
     *
     * @param  array<string, mixed>  $feed
     */
    private function write(array $feed, string $url): void
    {
        $tmp = $this->recordPath.'.tmp';

        file_put_contents($tmp, json_encode(['feed' => $url, 'signed' => $feed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $this->recordPath);
    }

    /**
     * Runs $work while holding a lock of its own on the record, so two
     * requests that finish a check together can't write over each other and
     * leave the older release in the record. The lock waits, which costs
     * milliseconds because the work is only local files. Returns null when
     * the lock file can't be opened.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    private function locked(callable $work): mixed
    {
        $handle = fopen($this->recordPath.'.lock', 'c');

        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_EX);

            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
