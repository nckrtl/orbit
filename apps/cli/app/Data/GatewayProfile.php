<?php

declare(strict_types=1);

namespace App\Data;

/** @mago-expect lint:cyclomatic-complexity Profile fields require independent security checks. */
final readonly class GatewayProfile
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $caPath = null,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Persisted profile values enter through a validated JSON boundary.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $name, array $data): ?self
    {
        $url = $data['url'] ?? null;
        $caPath = $data['ca_path'] ?? null;

        if (
            ! is_string($url)
            || $caPath !== null
            && ! is_string($caPath)
            || ! self::hasValidName($name)
            || ! self::hasSafeUrl($url)
            || ! self::hasValidCaPath($caPath)
        ) {
            return null;
        }

        return new self(
            name: $name,
            url: rtrim(string: $url, characters: '/'),
            caPath: $caPath,
        );
    }

    public static function hasValidName(string $name): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._-]{0,62}\z/D', $name) === 1;
    }

    public static function hasSafeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        $hostValue = is_string($host) ? trim(string: $host, characters: '[]') : '';
        $port = is_array($parts) ? $parts['port'] ?? null : null;
        $validHost =
            filter_var($hostValue, FILTER_VALIDATE_IP) !== false
            || filter_var($hostValue, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        return (
            is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && $validHost
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && in_array($parts['path'] ?? '', ['', '/'], strict: true)
            && ($port === null || $port >= 1)
        );
    }

    public static function hasValidCaPath(?string $caPath): bool
    {
        return (
            $caPath === null
            || str_starts_with($caPath, '/')
            && strlen($caPath) <= 4096
            && preg_match('/[\x00\r\n]/', $caPath) !== 1
        );
    }

    /** @return array{url: string, ca_path: ?string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'ca_path' => $this->caPath,
        ];
    }
}
