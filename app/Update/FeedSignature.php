<?php

namespace App\Update;

use InvalidArgumentException;

/**
 * Signs and checks the fields of a release.json with Ed25519, so an update
 * feed is only trusted when it was made with the release key.
 *
 * The signature covers version, zip, sha256 and published_at as written in
 * the feed, so the checksum it carries vouches for the zip as well. This is
 * plain PHP with no framework, because the release build signs with it too.
 */
final class FeedSignature
{
    /**
     * The public half of the release key, as base64 of its 32 bytes.
     */
    public const PUBLIC_KEY = 'y1IEVnapzcF3Xce2yReaLC0yIYm1ahpOlt98L2nTDfQ=';

    /**
     * Part of every signed message, so a signature made for anything else
     * can't pass as a feed's.
     */
    private const CONTEXT = 'starsystem-release-feed-v1';

    /**
     * @param  array<string, mixed>  $feed  The fields of a release.json, without its signature.
     * @param  string  $secretKey  Base64 of the 64-byte secret key.
     * @return string The signature, as base64.
     */
    public static function sign(array $feed, string $secretKey): string
    {
        $message = self::message($feed);
        $key = base64_decode($secretKey, true);

        if ($message === null) {
            throw new InvalidArgumentException('The feed needs a version, zip and sha256 that are strings to be signed.');
        }

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('The signing key must be the base64 of a 64-byte Ed25519 secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $key));
    }

    /**
     * Whether the feed's `signature` field is a valid signature of its other
     * fields by the holder of the given public key. Anything malformed is
     * simply not valid.
     *
     * @param  array<string, mixed>  $feed
     * @param  string  $publicKey  Base64 of the 32-byte public key.
     */
    public static function verify(array $feed, string $publicKey): bool
    {
        $message = self::message($feed);
        $key = base64_decode($publicKey, true);
        $signature = is_string($feed['signature'] ?? null) ? base64_decode($feed['signature'], true) : false;

        if ($message === null || $key === false || $signature === false) {
            return false;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $key);
    }

    /**
     * A JSON list has no ambiguous field boundaries, unlike fields joined by
     * a separator that a value could contain.
     *
     * @param  array<string, mixed>  $feed
     */
    private static function message(array $feed): ?string
    {
        $fields = [$feed['version'] ?? null, $feed['zip'] ?? null, $feed['sha256'] ?? null, $feed['published_at'] ?? ''];

        foreach ($fields as $field) {
            if (! is_string($field)) {
                return null;
            }
        }

        $message = json_encode([self::CONTEXT, ...$fields], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $message === false ? null : $message;
    }
}
