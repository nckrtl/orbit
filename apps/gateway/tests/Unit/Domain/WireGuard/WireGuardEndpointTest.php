<?php

declare(strict_types=1);

use App\Domain\WireGuard\WireGuardEndpoint;

it('accepts safe WireGuard endpoint forms', function (string $endpoint): void {
    expect(WireGuardEndpoint::isValid($endpoint))->toBeTrue();
})->with([
    'IPv4' => '10.0.0.2:51820',
    'hostname' => 'vpn.private.example:51820',
    'bracketed IPv6' => '[fd00::2]:51820',
]);

it('formats host and port values without changing non-IPv6 host bytes', function (
    string $host,
    int $port,
    string $endpoint,
): void {
    expect(WireGuardEndpoint::format($host, $port))->toBe($endpoint);
})->with([
    'IPv4' => ['192.0.2.10', 51_820, '192.0.2.10:51820'],
    'hostname' => ['Vpn.Example.test', 443, 'Vpn.Example.test:443'],
    'IPv6' => ['2001:db8::10', 51_820, '[2001:db8::10]:51820'],
]);

it('rejects unsafe or incomplete WireGuard endpoint forms', function (string $endpoint): void {
    expect(WireGuardEndpoint::isValid($endpoint))->toBeFalse();
})->with([
    'missing port' => '10.0.0.2',
    'zero port' => '10.0.0.2:0',
    'port above maximum' => '10.0.0.2:70000',
    'non-numeric port' => '10.0.0.2:wireguard',
    'bare IPv6' => 'fd00::2:51820',
    'whitespace' => 'vpn.private.example :51820',
    'shell control' => "10.0.0.2:51820\nPostUp = touch /tmp/orbit-injected",
]);
