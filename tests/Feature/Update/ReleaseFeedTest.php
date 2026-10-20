<?php

namespace Tests\Feature\Update;

use App\Update\ReleaseFeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsFeeds;
use Tests\TestCase;

class ReleaseFeedTest extends TestCase
{
    use SignsFeeds;

    private const URL = 'https://feed.example/downloads/release.json';

    /**
     * @var array<string, string>
     */
    private const FIELDS = [
        'version' => '1.1.0',
        'zip' => 'starsystem-1.1.0.zip',
        'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        'published_at' => '2026-02-01T00:00:00Z',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useReleaseKey();
        config(['starsystem.updates.feed' => self::URL]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function serve(array $body): void
    {
        Http::fake([self::URL => Http::response($body)]);
    }

    public function test_a_signed_feed_is_accepted(): void
    {
        $this->serve($this->signedFeed(self::FIELDS));

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertNull($latest['error']);
        $this->assertSame('1.1.0', $latest['release']?->version);
    }

    public function test_the_zip_is_signed_as_written_and_resolved_afterwards(): void
    {
        $this->serve($this->signedFeed(self::FIELDS));

        $this->assertSame(
            'https://feed.example/downloads/starsystem-1.1.0.zip',
            app(ReleaseFeed::class)->latest()['release']?->zip,
        );
    }

    public function test_an_unsigned_feed_is_refused(): void
    {
        $this->serve(self::FIELDS);

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertNull($latest['release']);
        $this->assertStringContainsString("isn't signed", (string) $latest['error']);
    }

    public function test_a_feed_changed_after_signing_is_refused(): void
    {
        $feed = $this->signedFeed(self::FIELDS);

        $this->serve([...$feed, 'sha256' => str_repeat('a', 64)]);

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertNull($latest['release']);
        $this->assertStringContainsString("isn't signed", (string) $latest['error']);
    }

    public function test_a_feed_signed_with_another_key_is_refused(): void
    {
        [$other] = self::releaseKeyPair();

        $this->serve($this->signedFeed(self::FIELDS, $other));

        $this->assertNull(app(ReleaseFeed::class)->latest()['release']);
    }

    public function test_without_a_release_key_every_feed_is_refused(): void
    {
        config(['starsystem.updates.public_key' => '']);
        $this->serve($this->signedFeed(self::FIELDS));

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertNull($latest['release']);
        $this->assertStringContainsString('no release key', (string) $latest['error']);
    }

    public function test_a_body_that_is_not_a_feed_is_still_described_as_such(): void
    {
        Http::fake([self::URL => Http::response('not json')]);

        $this->assertStringContainsString("isn't a release description", (string) app(ReleaseFeed::class)->latest()['error']);
    }

    public function test_an_answer_cached_before_feeds_were_checked_is_not_used(): void
    {
        Cache::put('starsystem.updates.feed', [
            'release' => ['version' => '9.9.9', 'zip' => 'https://evil.example/a.zip', 'sha256' => str_repeat('a', 64), 'published_at' => null],
            'error' => null,
            'checkedAt' => '2026-02-01T00:00:00+00:00',
        ]);
        $this->serve(self::FIELDS);

        $this->assertNull(app(ReleaseFeed::class)->latest()['release']);
    }
}
