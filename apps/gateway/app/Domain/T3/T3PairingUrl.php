<?php

declare(strict_types=1);

namespace App\Domain\T3;

use SensitiveParameter;

/**
 * T3's pairing link format, `<origin>/pair#token=<token>`.
 */
final readonly class T3PairingUrl
{
    /** A pairing link to `$baseUrl` for `$token`, built the way T3's own `buildPairingUrl` does. */
    public static function build(string $baseUrl, #[SensitiveParameter] string $token): string
    {
        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/pair#'.http_build_query(['token' => $token], encoding_type: PHP_QUERY_RFC1738);
    }
}
