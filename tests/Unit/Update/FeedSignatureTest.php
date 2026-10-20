<?php

namespace Tests\Unit\Update;

use App\Update\FeedSignature;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedSignatureTest extends TestCase
{
    private const SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * @var array<string, string>
     */
    private const FEED = [
        'version' => '1.2.3',
        'zip' => 'https://example.com/starsystem-1.2.3.zip',
        'sha256' => self::SHA,
        'published_at' => '2026-01-01T00:00:00Z',
    ];

    /**
     * @return array{secret: string, public: string}
     */
    private static function keys(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function signed(array $fields, string $secret): array
    {
        return [...$fields, 'signature' => FeedSignature::sign($fields, $secret)];
    }

    public function test_a_signed_feed_verifies_with_the_matching_public_key(): void
    {
        $keys = self::keys();

        $this->assertTrue(FeedSignature::verify(self::signed(self::FEED, $keys['secret']), $keys['public']));
    }

    public function test_a_feed_without_published_at_can_be_signed_and_checked(): void
    {
        $keys = self::keys();
        $feed = self::signed(['version' => '1.2.3', 'zip' => 'a.zip', 'sha256' => self::SHA], $keys['secret']);

        $this->assertTrue(FeedSignature::verify($feed, $keys['public']));
        $this->assertFalse(FeedSignature::verify([...$feed, 'published_at' => '2026-01-01T00:00:00Z'], $keys['public']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tamperedFields(): iterable
    {
        yield 'version' => ['version', '9.9.9'];
        yield 'zip' => ['zip', 'https://evil.example/starsystem-1.2.3.zip'];
        yield 'sha256' => ['sha256', str_repeat('a', 64)];
        yield 'published_at' => ['published_at', '2030-01-01T00:00:00Z'];
    }

    #[DataProvider('tamperedFields')]
    public function test_changing_a_signed_field_breaks_the_signature(string $field, string $value): void
    {
        $keys = self::keys();
        $feed = self::signed(self::FEED, $keys['secret']);

        $this->assertFalse(FeedSignature::verify([...$feed, $field => $value], $keys['public']));
    }

    public function test_a_signature_made_with_another_key_is_refused(): void
    {
        $theirs = self::keys();
        $ours = self::keys();

        $this->assertFalse(FeedSignature::verify(self::signed(self::FEED, $theirs['secret']), $ours['public']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badSignatures(): iterable
    {
        yield 'absent' => [null];
        yield 'not a string' => [123];
        yield 'not base64' => ['!!! not base64 !!!'];
        yield 'too short' => [base64_encode('short')];
        yield 'empty' => [''];
    }

    #[DataProvider('badSignatures')]
    public function test_a_missing_or_malformed_signature_is_refused(mixed $signature): void
    {
        $keys = self::keys();
        $feed = $signature === null ? self::FEED : [...self::FEED, 'signature' => $signature];

        $this->assertFalse(FeedSignature::verify($feed, $keys['public']));
    }

    public function test_a_feed_missing_a_signed_field_is_refused(): void
    {
        $keys = self::keys();
        $feed = self::signed(self::FEED, $keys['secret']);
        unset($feed['zip']);

        $this->assertFalse(FeedSignature::verify($feed, $keys['public']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPublicKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64' => ['!!! not base64 !!!'];
        yield 'wrong length' => [base64_encode('too short for a key')];
    }

    #[DataProvider('badPublicKeys')]
    public function test_a_missing_or_malformed_public_key_refuses_everything(string $publicKey): void
    {
        $keys = self::keys();

        $this->assertFalse(FeedSignature::verify(self::signed(self::FEED, $keys['secret']), $publicKey));
    }

    public function test_a_signature_over_the_bare_fields_does_not_pass_as_a_feed_signature(): void
    {
        $keys = self::keys();
        $bare = json_encode(array_values(self::FEED), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = base64_encode(sodium_crypto_sign_detached((string) $bare, (string) base64_decode($keys['secret'], true)));

        $this->assertFalse(FeedSignature::verify([...self::FEED, 'signature' => $signature], $keys['public']));
    }

    public function test_signing_refuses_a_feed_without_the_fields_or_a_key_of_the_wrong_size(): void
    {
        $keys = self::keys();

        try {
            FeedSignature::sign(['version' => '1.0.0'], $keys['secret']);
            $this->fail('A feed with no zip or sha256 was signed.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);

        FeedSignature::sign(self::FEED, base64_encode('not a secret key'));
    }

    public function test_the_shipped_public_key_has_the_size_of_a_public_key(): void
    {
        $key = base64_decode(FeedSignature::PUBLIC_KEY, true);

        $this->assertIsString($key);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($key));
    }
}
