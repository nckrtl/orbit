<?php

declare(strict_types=1);

use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Shared\ResourceOperationException;

describe(CustomProxyUpstream::class, function (): void {
    it('accepts loopback HTTP URLs with an explicit port', function (string $url, string $host, int $port, string $authority): void {
        $upstream = CustomProxyUpstream::parse($url);

        expect($upstream->host)
            ->toBe($host)
            ->and($upstream->port)
            ->toBe($port)
            ->and($upstream->authority())
            ->toBe($authority)
            ->and($upstream->url())
            ->toBe('http://'.$authority);
    })->with([
        'ipv4' => ['http://127.0.0.1:4788', '127.0.0.1', 4788, '127.0.0.1:4788'],
        'localhost' => ['http://localhost:4788', '127.0.0.1', 4788, '127.0.0.1:4788'],
        'ipv6' => ['http://[::1]:4788', '::1', 4788, '[::1]:4788'],
    ]);

    it('rejects remote, encrypted, or extra-shaped upstreams', function (string $url): void {
        expect(fn (): CustomProxyUpstream => CustomProxyUpstream::parse($url))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.upstream_invalid');
            });
    })->with([
        'https' => ['https://127.0.0.1:4788'],
        'missing port' => ['http://127.0.0.1'],
        'remote host' => ['http://10.44.0.8:4788'],
        'public host' => ['http://example.test:80'],
        'userinfo' => ['http://user:secret@127.0.0.1:4788'],
        'path' => ['http://127.0.0.1:4788/status'],
        'query' => ['http://127.0.0.1:4788?q=1'],
        'fragment' => ['http://127.0.0.1:4788#frag'],
    ]);
});
