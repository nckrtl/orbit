<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;

final readonly class CustomProxyUpstream
{
    private const array LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    public function __construct(
        public string $host,
        public int $port,
    ) {}

    public static function parse(string $url): self
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ! is_string($parts['host'] ?? null)
            || ! is_int($parts['port'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw self::invalid();
        }

        $path = array_key_exists('path', $parts) ? $parts['path'] : '';

        if ($path !== '' && $path !== '/') {
            throw self::invalid();
        }

        $host = strtolower($parts['host']);

        if ($host === '[::1]') {
            $host = '::1';
        }

        if (! in_array($host, self::LOOPBACK_HOSTS, true)) {
            throw self::invalid();
        }

        $port = $parts['port'];

        if ($port < 1) {
            throw self::invalid();
        }

        return new self(host: $host === 'localhost' ? '127.0.0.1' : $host, port: $port);
    }

    public function authority(): string
    {
        $host = $this->host === '::1' ? '[::1]' : $this->host;

        return "{$host}:{$this->port}";
    }

    public function url(): string
    {
        return 'http://'.$this->authority();
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'route.upstream_invalid',
            message: 'A custom proxy upstream must be a loopback HTTP URL on the serving Node.',
        );
    }
}
