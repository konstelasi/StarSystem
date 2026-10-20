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
 */
class ReleaseFeed
{
    /**
     * Not the key earlier versions cached under, whose answers were never
     * checked and so mustn't be read as if they had been.
     */
    public const CACHE_KEY = 'starsystem.updates.feed.signed';

    public const CACHE_HOURS = 12;

    public function url(): ?string
    {
        $url = config('starsystem.updates.feed');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array{release: ?Release, error: ?string, checkedAt: ?string}
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
            'checkedAt' => $cached['checkedAt'] ?? null,
        ];
    }

    /**
     * Only arrays go into the cache, so a cached answer still reads after
     * an update has changed the classes.
     *
     * @return array{release: ?array<string, mixed>, error: ?string, checkedAt: string}
     */
    private function fetch(): array
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $url = $this->url();

        if ($url === null) {
            return ['release' => null, 'error' => 'Update checks are turned off on this install.', 'checkedAt' => $now];
        }

        try {
            $response = Http::timeout(15)->connectTimeout(10)->acceptJson()->get($url)->throw();
            $feed = $response->json();

            $this->assertSigned($feed);

            return ['release' => Release::fromFeed($feed, $url)->toArray(), 'error' => null, 'checkedAt' => $now];
        } catch (UpdateException $e) {
            return ['release' => null, 'error' => $e->getMessage(), 'checkedAt' => $now];
        } catch (Throwable $e) {
            Log::warning('StarSystem update check failed.', ['exception' => $e]);

            return ['release' => null, 'error' => 'StarSystem couldn\'t reach the update feed. Try again later.', 'checkedAt' => $now];
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
}
