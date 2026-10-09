<?php

declare(strict_types=1);

namespace App\Domain\T3;

use SensitiveParameter;

/**
 * T3's pairing link format, `<origin>/pair#token=<token>`. T3 also reads a `token` query parameter.
 */
final readonly class T3PairingUrl
{
    /** The pairing token in a T3 pairing link, or null when the link has none. */
    public static function token(#[SensitiveParameter] string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        foreach ([$parts['fragment'] ?? '', $parts['query'] ?? ''] as $params) {
            parse_str($params, $values);
            $token = $values['token'] ?? null;

            if (is_string($token) && trim($token) !== '') {
                return trim($token);
            }
        }

        return null;
    }

    /** A pairing link to `$baseUrl` for `$token`, built the way T3's own `buildPairingUrl` does. */
    public static function build(string $baseUrl, #[SensitiveParameter] string $token): string
    {
        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/pair#'.http_build_query(['token' => $token], encoding_type: PHP_QUERY_RFC1738);
    }
}
