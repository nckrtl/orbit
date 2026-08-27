<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use SensitiveParameter;

/** @internal */
/** @mago-expect lint:cyclomatic-complexity Safe origins reject each unsafe URL component. */
final class GatewayOrigin
{
    public static function fromTransport(#[SensitiveParameter] string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        $hostValue = is_string($host) ? trim(string: $host, characters: '[]') : '';
        $port = is_array($parts) ? $parts['port'] ?? null : null;
        $validHost =
            filter_var($hostValue, FILTER_VALIDATE_IP) !== false
            || filter_var($hostValue, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (
            ! is_array($parts)
            || ! is_string($scheme)
            || strtolower($scheme) !== 'https'
            || ! $validHost
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ! in_array($parts['path'] ?? '', ['', '/'], strict: true)
            || $port !== null
            && $port < 1
        ) {
            return null;
        }

        $origin = "https://{$host}";

        return is_int($port) ? "{$origin}:{$port}" : $origin;
    }
}
