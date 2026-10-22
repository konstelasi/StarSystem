<?php

namespace Tests\Feature\Update;

use App\Update\ReleaseFeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * Answer each check with the next feed, so one test can see the feed
     * change.
     *
     * @param  array<string, mixed>  ...$bodies
     */
    private function serveInOrder(array ...$bodies): void
    {
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push($body);
        }

        Http::fake([self::URL => $sequence]);
    }

    /**
     * @param  array<string, string>  $changes
     * @return array<string, mixed>
     */
    private function feedOf(string $version, array $changes = [], ?string $secret = null): array
    {
        return $this->signedFeed([...self::FIELDS, 'version' => $version, 'zip' => "starsystem-{$version}.zip", ...$changes], $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        return json_decode((string) file_get_contents($this->feedRecord), true);
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

    public function test_the_first_signed_feed_is_remembered(): void
    {
        $this->serve($this->feedOf('1.1.0'));

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertNull($latest['warning']);
        $this->assertSame(self::URL, $this->record()['feed']);
        $this->assertSame('1.1.0', $this->record()['signed']['version']);
    }

    public function test_a_newer_feed_replaces_the_record(): void
    {
        $this->serveInOrder($this->feedOf('1.1.0'), $this->feedOf('1.2.0'));

        app(ReleaseFeed::class)->latest();
        $latest = app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertSame('1.2.0', $latest['release']?->version);
        $this->assertNull($latest['warning']);
        $this->assertSame('1.2.0', $this->record()['signed']['version']);
    }

    public function test_an_older_feed_after_a_newer_one_offers_the_newer_release_with_a_warning(): void
    {
        $this->serveInOrder($this->feedOf('1.2.0'), $this->feedOf('1.1.0'));

        app(ReleaseFeed::class)->latest();
        $latest = app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertNull($latest['error']);
        $this->assertSame('1.2.0', $latest['release']?->version);
        $this->assertSame('https://feed.example/downloads/starsystem-1.2.0.zip', $latest['release']->zip);
        $this->assertStringContainsString('1.1.0', (string) $latest['warning']);
        $this->assertStringContainsString('1.2.0', (string) $latest['warning']);
        $this->assertSame('1.2.0', $this->record()['signed']['version']);
    }

    public function test_the_warning_stays_for_the_cached_answer(): void
    {
        $this->serveInOrder($this->feedOf('1.2.0'), $this->feedOf('1.1.0'));

        app(ReleaseFeed::class)->latest();
        app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertNotNull(app(ReleaseFeed::class)->latest()['warning']);
    }

    public function test_an_older_feed_with_a_bad_signature_changes_nothing(): void
    {
        [$other] = self::releaseKeyPair();
        $this->serveInOrder($this->feedOf('1.2.0'), $this->feedOf('1.1.0', secret: $other));

        app(ReleaseFeed::class)->latest();
        $latest = app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertNull($latest['release']);
        $this->assertNull($latest['warning']);
        $this->assertSame('1.2.0', $this->record()['signed']['version']);
    }

    public function test_a_feed_that_is_refused_leaves_no_record(): void
    {
        $this->serve($this->feedOf('1.1.0', ['zip' => 'http://feed.example/starsystem-1.1.0.zip']));

        $this->assertNull(app(ReleaseFeed::class)->latest()['release']);
        $this->assertFileDoesNotExist($this->feedRecord);
    }

    public function test_an_unsigned_feed_leaves_no_record(): void
    {
        $this->serve(self::FIELDS);

        app(ReleaseFeed::class)->latest();

        $this->assertFileDoesNotExist($this->feedRecord);
    }

    public function test_turning_checks_off_leaves_no_record(): void
    {
        config(['starsystem.updates.feed' => '']);

        app(ReleaseFeed::class)->latest();

        $this->assertFileDoesNotExist($this->feedRecord);
    }

    public function test_a_record_that_is_not_readable_counts_as_none(): void
    {
        file_put_contents($this->feedRecord, 'not json');
        $this->serve($this->feedOf('1.1.0'));

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertSame('1.1.0', $latest['release']?->version);
        $this->assertNull($latest['warning']);
        $this->assertSame('1.1.0', $this->record()['signed']['version']);
    }

    public function test_a_record_made_for_another_feed_counts_as_none(): void
    {
        $this->serve($this->feedOf('1.2.0'));
        app(ReleaseFeed::class)->latest();

        $other = 'https://staging.example/release.json';
        config(['starsystem.updates.feed' => $other]);
        Http::fake([$other => Http::response($this->feedOf('1.1.0'))]);

        $latest = app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertSame('1.1.0', $latest['release']?->version);
        $this->assertNull($latest['warning']);
        $this->assertSame($other, $this->record()['feed']);
    }

    public function test_a_record_signed_with_an_earlier_key_counts_as_none(): void
    {
        $live = $this->feedOf('1.2.0');
        Http::fake([self::URL => function () use (&$live) {
            return Http::response($live);
        }]);
        app(ReleaseFeed::class)->latest();

        $this->useReleaseKey();
        $live = $this->feedOf('1.1.0');

        $latest = app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertSame('1.1.0', $latest['release']?->version);
        $this->assertNull($latest['warning']);
        $this->assertSame('1.1.0', $this->record()['signed']['version']);
    }

    public function test_the_same_version_with_another_checksum_is_offered_and_not_recorded(): void
    {
        $this->serveInOrder($this->feedOf('1.1.0'), $this->feedOf('1.1.0', ['sha256' => str_repeat('b', 64)]));

        app(ReleaseFeed::class)->latest();
        $latest = app(ReleaseFeed::class)->latest(fresh: true);

        $this->assertSame(str_repeat('b', 64), $latest['release']?->sha256);
        $this->assertNull($latest['warning']);
        $this->assertSame(self::FIELDS['sha256'], $this->record()['signed']['sha256']);
    }

    public function test_flushing_the_cache_does_not_reset_the_record(): void
    {
        $this->serveInOrder($this->feedOf('1.2.0'), $this->feedOf('1.1.0'));

        app(ReleaseFeed::class)->latest();
        Cache::flush();

        $this->assertNotNull(app(ReleaseFeed::class)->latest()['warning']);
    }

    public function test_a_record_that_cannot_be_kept_does_not_cost_the_check(): void
    {
        Log::spy();
        $this->serve($this->feedOf('1.1.0'));

        $feed = new ReleaseFeed(sys_get_temp_dir().'/starsystem-no-such-folder-'.bin2hex(random_bytes(6)).'/seen.json');
        $latest = $feed->latest();

        $this->assertNull($latest['error']);
        $this->assertNull($latest['warning']);
        $this->assertSame('1.1.0', $latest['release']?->version);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_answer_cached_before_warnings_existed_reads_as_no_warning(): void
    {
        Cache::put(ReleaseFeed::CACHE_KEY, [
            'release' => ['version' => '1.1.0', 'zip' => 'https://feed.example/a.zip', 'sha256' => str_repeat('a', 64), 'published_at' => null],
            'error' => null,
            'checkedAt' => '2026-02-01T00:00:00+00:00',
        ]);

        $latest = app(ReleaseFeed::class)->latest();

        $this->assertSame('1.1.0', $latest['release']?->version);
        $this->assertNull($latest['warning']);
    }
}
