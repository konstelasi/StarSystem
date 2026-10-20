<?php

namespace Tests\Concerns;

use App\Update\FeedSignature;

/**
 * Update feeds are only believed when signed, so a test that fakes one
 * trusts a release key of its own and signs with it.
 */
trait SignsFeeds
{
    private string $releaseSecret = '';

    /**
     * Trust a fresh release key for the rest of the test.
     */
    protected function useReleaseKey(): void
    {
        [$this->releaseSecret, $public] = self::releaseKeyPair();

        config(['starsystem.updates.public_key' => $public]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function signedFeed(array $fields, ?string $secret = null): array
    {
        return [...$fields, 'signature' => FeedSignature::sign($fields, $secret ?? $this->releaseSecret)];
    }

    /**
     * @return array{string, string} The secret and the public half, as base64.
     */
    protected static function releaseKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [base64_encode(sodium_crypto_sign_secretkey($pair)), base64_encode(sodium_crypto_sign_publickey($pair))];
    }
}
