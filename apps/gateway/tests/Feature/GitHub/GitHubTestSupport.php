<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use RuntimeException;

final class GitHubTestSupport
{
    private static ?string $privateKey = null;

    public static function privateKey(): string
    {
        if (self::$privateKey !== null) {
            return self::$privateKey;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Unable to create a test key.');
        }

        return self::$privateKey = $pem;
    }

    public static function credentials(): GitHubAppCredentials
    {
        return new GitHubAppCredentials(
            appId: 4242,
            slug: 'orbit-acme',
            name: 'orbit-acme',
            owner: 'acme',
            ownerType: 'organization',
            url: 'https://github.com/apps/orbit-acme',
            privateKey: self::privateKey(),
        );
    }

    public static function storeApp(): GitHubAppCredentials
    {
        $credentials = self::credentials();
        app(GitHubAppStore::class)->put($credentials);

        return $credentials;
    }
}
