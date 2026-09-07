<?php

declare(strict_types=1);

use App\Domain\WireGuard\Ipv4Subnet;

it('represents each supported canonical subnet boundary', function (
    string $value,
    string $networkAddress,
    int $prefixLength,
    string $firstUsableAddress,
    string $lastUsableAddress,
): void {
    $subnet = Ipv4Subnet::from($value);

    expect($subnet->value())
        ->toBe($value)
        ->and($subnet->networkAddress())
        ->toBe($networkAddress)
        ->and($subnet->prefixLength())
        ->toBe($prefixLength)
        ->and($subnet->usableRange())
        ->toBe([$firstUsableAddress, $lastUsableAddress]);
})->with([
    '/8' => ['10.0.0.0/8', '10.0.0.0', 8, '10.0.0.1', '10.255.255.254'],
    '/24' => ['10.44.0.0/24', '10.44.0.0', 24, '10.44.0.1', '10.44.0.254'],
    '/30' => ['10.44.0.0/30', '10.44.0.0', 30, '10.44.0.1', '10.44.0.2'],
]);

it('rejects invalid and noncanonical subnet values', function (string $value): void {
    expect(fn (): Ipv4Subnet => Ipv4Subnet::from($value))
        ->toThrow(InvalidArgumentException::class, "IPv4 subnet [{$value}] is invalid.");
})->with([
    'missing prefix' => '10.44.0.0',
    'bad address' => 'not-an-address/24',
    'IPv6' => '2001:db8::/64',
    'prefix below policy' => '10.0.0.0/7',
    'prefix above policy' => '10.44.0.0/31',
    'host bits' => '10.44.0.1/24',
]);

it('distinguishes subnet membership from usable host addresses', function (): void {
    $subnet = Ipv4Subnet::from('10.44.0.0/30');

    expect($subnet->contains('10.44.0.0'))
        ->toBeTrue()
        ->and($subnet->contains('10.44.0.3'))
        ->toBeTrue()
        ->and($subnet->contains('10.44.0.4'))
        ->toBeFalse()
        ->and($subnet->contains('2001:db8::1'))
        ->toBeFalse()
        ->and($subnet->containsUsableAddress('10.44.0.0'))
        ->toBeFalse()
        ->and($subnet->containsUsableAddress('10.44.0.1'))
        ->toBeTrue()
        ->and($subnet->containsUsableAddress('10.44.0.2'))
        ->toBeTrue()
        ->and($subnet->containsUsableAddress('10.44.0.3'))
        ->toBeFalse()
        ->and(iterator_to_array($subnet->usableAddresses(), preserve_keys: false))
        ->toBe(['10.44.0.1', '10.44.0.2']);
});
