<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;

/**
 * Signs the short-lived RS256 token that authenticates the Gateway as its GitHub App. The issue time
 * is set one minute back to tolerate clock drift, and the token lives for nine minutes; GitHub
 * refuses more than ten.
 */
final readonly class GitHubAppJwt
{
    public static function sign(GitHubAppCredentials $credentials, int $now): string
    {
        $header = self::encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::encode(json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => (string) $credentials->appId,
        ], JSON_THROW_ON_ERROR));

        $key = openssl_pkey_get_private($credentials->privateKey);

        if ($key === false || ! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw GitHubApiException::unavailable();
        }

        return "{$header}.{$claims}.".self::encode($signature);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
