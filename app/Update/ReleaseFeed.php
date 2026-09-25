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
 */
class ReleaseFeed
{
    public const CACHE_KEY = 'starsystem.updates.feed';

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

            return ['release' => Release::fromFeed($response->json(), $url)->toArray(), 'error' => null, 'checkedAt' => $now];
        } catch (UpdateException $e) {
            return ['release' => null, 'error' => $e->getMessage(), 'checkedAt' => $now];
        } catch (Throwable $e) {
            Log::warning('StarSystem update check failed.', ['exception' => $e]);

            return ['release' => null, 'error' => 'StarSystem couldn\'t reach the update feed. Try again later.', 'checkedAt' => $now];
        }
    }
}
