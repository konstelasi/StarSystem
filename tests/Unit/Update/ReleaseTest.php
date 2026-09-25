<?php

namespace Tests\Unit\Update;

use App\Update\Release;
use App\Update\UpdateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseTest extends TestCase
{
    private const SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_a_well_formed_feed_is_accepted(): void
    {
        $release = Release::fromFeed(
            ['version' => '1.2.3', 'zip' => 'https://cdn.example/starsystem-1.2.3.zip', 'sha256' => self::SHA, 'published_at' => '2026-01-01T00:00:00Z'],
            'https://example.com/release.json',
        );

        $this->assertSame('1.2.3', $release->version);
        $this->assertSame('https://cdn.example/starsystem-1.2.3.zip', $release->zip);
        $this->assertSame(self::SHA, $release->sha256);
        $this->assertSame('2026-01-01T00:00:00Z', $release->publishedAt);
    }

    public function test_a_relative_zip_url_resolves_against_the_feed(): void
    {
        $release = Release::fromFeed(
            ['version' => '1.2.3', 'zip' => 'starsystem-1.2.3.zip', 'sha256' => self::SHA],
            'https://example.com/downloads/release.json',
        );

        $this->assertSame('https://example.com/downloads/starsystem-1.2.3.zip', $release->zip);
    }

    public function test_a_root_relative_zip_url_resolves_against_the_feed_origin(): void
    {
        $release = Release::fromFeed(
            ['version' => '1.2.3', 'zip' => '/dl/starsystem-1.2.3.zip', 'sha256' => self::SHA],
            'https://example.com:8443/downloads/release.json',
        );

        $this->assertSame('https://example.com:8443/dl/starsystem-1.2.3.zip', $release->zip);
    }

    public function test_a_non_array_feed_is_refused(): void
    {
        $this->expectException(UpdateException::class);

        Release::fromFeed('not json', 'https://example.com/release.json');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidFeeds(): iterable
    {
        yield 'missing version' => [['zip' => 'a.zip', 'sha256' => self::SHA]];
        yield 'malformed version' => [['version' => 'v1', 'zip' => 'a.zip', 'sha256' => self::SHA]];
        yield 'missing sha256' => [['version' => '1.0.0', 'zip' => 'a.zip']];
        yield 'malformed sha256' => [['version' => '1.0.0', 'zip' => 'a.zip', 'sha256' => 'nope']];
        yield 'missing zip' => [['version' => '1.0.0', 'sha256' => self::SHA]];
        yield 'empty zip' => [['version' => '1.0.0', 'zip' => '', 'sha256' => self::SHA]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidFeeds')]
    public function test_an_invalid_feed_is_refused(array $data): void
    {
        $this->expectException(UpdateException::class);

        Release::fromFeed($data, 'https://example.com/release.json');
    }

    public function test_a_plain_http_zip_under_an_https_feed_is_refused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage("won't use");

        Release::fromFeed(
            ['version' => '1.0.0', 'zip' => 'http://example.com/a.zip', 'sha256' => self::SHA],
            'https://example.com/release.json',
        );
    }

    public function test_an_http_zip_under_an_http_feed_is_allowed(): void
    {
        $release = Release::fromFeed(
            ['version' => '1.0.0', 'zip' => 'http://example.com/a.zip', 'sha256' => self::SHA],
            'http://example.com/release.json',
        );

        $this->assertSame('http://example.com/a.zip', $release->zip);
    }

    public function test_a_non_http_scheme_is_refused(): void
    {
        $this->expectException(UpdateException::class);

        Release::fromFeed(
            ['version' => '1.0.0', 'zip' => 'file:///etc/passwd', 'sha256' => self::SHA],
            'https://example.com/release.json',
        );
    }

    public function test_newer_than_compares_semver(): void
    {
        $release = Release::fromArray(['version' => '1.2.0', 'zip' => 'a.zip', 'sha256' => self::SHA]);

        $this->assertTrue($release->newerThan('1.1.9'));
        $this->assertFalse($release->newerThan('1.2.0'));
        $this->assertFalse($release->newerThan('1.3.0'));
    }

    public function test_array_round_trips(): void
    {
        $release = Release::fromArray(['version' => '1.0.0', 'zip' => 'a.zip', 'sha256' => self::SHA, 'published_at' => null]);

        $this->assertSame(
            ['version' => '1.0.0', 'zip' => 'a.zip', 'sha256' => self::SHA, 'published_at' => null],
            $release->toArray(),
        );
    }
}
