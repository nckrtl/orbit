<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('resolves peer overrides and renders the complete server peer set', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => 'SERVER_PUBLIC',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_ip' => '10.44.0.2',
            'wireguard_public_key' => 'PEER_PUBLIC',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.2',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(subnet: '10.44.0.0/24', domain: 'test');
        $configuration = new VpnConfigurationRepository($settings, $orbitHome);

        $vpn = $configuration->forPeer($peer);
        $rendered = new WireGuardServerConfigRenderer()->render($vpn, Node::query()->get());

        expect($vpn->endpoint)
            ->toBe('10.0.0.2:51820')
            ->and($vpn->dnsServer)
            ->toBe('10.0.0.2')
            ->and($vpn->dnsThroughWireGuard)
            ->toBeFalse()
            ->and($vpn->peerAddress)
            ->toBe('10.44.0.2/24')
            ->and($rendered)
            ->toContain(
                'Address = 10.44.0.1/24',
                'PrivateKey = SERVER_PRIVATE',
                'PublicKey = PEER_PUBLIC',
                'AllowedIPs = 10.44.0.2/32',
            )
            ->not->toContain('PublicKey = SERVER_PUBLIC');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('preserves explicit endpoint forms at the peer configuration boundary', function (string $endpoint): void {
    $orbitHome = wireguard_configuration_home();

    try {
        [$settings, $peer] = wireguard_configuration_fixture();
        $peer->update(['wireguard_endpoint_override' => $endpoint]);

        $configuration = new VpnConfigurationRepository($settings, $orbitHome);

        expect($configuration->forPeer($peer)->endpoint)->toBe($endpoint);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'IPv4' => '192.0.2.10:51820',
    'hostname' => 'vpn.example.test:51820',
    'bracketed IPv6' => '[2001:db8::10]:51820',
]);

it('formats the public-host fallback for peer configuration', function (
    string $publicHost,
    string $endpoint,
): void {
    $orbitHome = wireguard_configuration_home();

    try {
        [$settings, $peer, $gateway] = wireguard_configuration_fixture();
        $gateway->update(['public_ssh_host' => $publicHost]);

        $configuration = new VpnConfigurationRepository($settings, $orbitHome);

        expect($configuration->forPeer($peer)->endpoint)->toBe($endpoint);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'IPv4 bytes' => ['192.0.2.10', '192.0.2.10:51820'],
    'hostname bytes' => ['Vpn.Example.test', 'Vpn.Example.test:51820'],
    'IPv6 brackets' => ['2001:db8::10', '[2001:db8::10]:51820'],
]);

it('rejects malformed endpoints at the peer configuration boundary', function (string $endpoint): void {
    $orbitHome = wireguard_configuration_home();

    try {
        [$settings, $peer] = wireguard_configuration_fixture();
        $peer->update(['wireguard_endpoint_override' => $endpoint]);

        $configuration = new VpnConfigurationRepository($settings, $orbitHome);

        expect(fn () => $configuration->forPeer($peer))
            ->toThrow(NodeProvisioningException::class, 'The WireGuard endpoint is invalid.');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'bare IPv6' => '2001:db8::10:51820',
    'invalid port' => '192.0.2.10:0',
    'whitespace' => 'vpn.example.test :51820',
]);

it('rejects unsafe endpoint, DNS, and domain values before rendering peer shell hooks', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => 'SERVER_PUBLIC',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_ip' => '10.44.0.2',
            'dns_server_override' => '10.0.0.2; touch /tmp/orbit-injected',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(subnet: '10.44.0.0/24');
        $configuration = new VpnConfigurationRepository($settings, $orbitHome);

        $peer->update([
            'wireguard_endpoint_override' => "10.0.0.2:51820\nPostUp = touch /tmp/orbit-injected",
        ]);

        expect(fn () => $configuration->forPeer($peer))
            ->toThrow(NodeProvisioningException::class, 'The WireGuard endpoint is invalid.');

        $peer->update(['wireguard_endpoint_override' => null]);

        expect(fn () => $configuration->forPeer($peer))
            ->toThrow(NodeProvisioningException::class, 'The DNS server is invalid.');

        $peer->update(['dns_server_override' => null]);

        expect($configuration->forPeer($peer)->dnsThroughWireGuard)
            ->toBeTrue();

        $peer->update(['dns_server_override' => '2001:db8::53']);

        expect($configuration->forPeer($peer)->dnsThroughWireGuard)
            ->toBeFalse();

        $peer->update(['dns_server_override' => '10.0.0.2']);
        $settings->configure(subnet: '10.44.0.0/24', domain: 'orbit; touch /tmp/orbit-injected');

        expect(fn () => $configuration->forPeer($peer))
            ->toThrow(NodeProvisioningException::class, 'The private DNS domain is invalid.');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects a noncanonical configured subnet with the stable configuration error', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $peer = Node::query()->create([
        'name' => 'app-dev',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(subnet: '10.44.0.1/24');
    $configuration = new VpnConfigurationRepository($settings, '/missing');

    expect(fn () => $configuration->forPeer($peer))
        ->toThrow(function (NodeProvisioningException $exception): void {
            expect($exception->errorCode)
                ->toBe('vpn.configuration_invalid')
                ->and($exception->getMessage())
                ->toBe('WireGuard subnet [10.44.0.1/24] is invalid.');
        });
});

it('rejects peer addresses outside the usable subnet range', function (string $address): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $peer = Node::query()->create([
        'name' => 'app-dev',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => $address,
    ]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(subnet: '10.44.0.0/30');
    $configuration = new VpnConfigurationRepository($settings, '/missing');

    expect(fn () => $configuration->forPeer($peer))
        ->toThrow(function (NodeProvisioningException $exception) use ($address): void {
            expect($exception->errorCode)
                ->toBe('vpn.configuration_invalid')
                ->and($exception->getMessage())
                ->toBe("Node [app-dev] has invalid WireGuard address [{$address}] for subnet [10.44.0.0/30].");
        });
})->with([
    'network' => '10.44.0.0',
    'broadcast' => '10.44.0.3',
    'outside' => '10.44.0.4',
    'IPv6' => '2001:db8::2',
]);

function wireguard_configuration_home(): string
{
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');

    return $orbitHome;
}

/** @return array{VpnSettings, Node, Node} */
function wireguard_configuration_fixture(): array
{
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => 'SERVER_PUBLIC',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $peer = Node::query()->create([
        'name' => 'app-dev',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.2',
        'wireguard_public_key' => 'PEER_PUBLIC',
    ]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(subnet: '10.44.0.0/24');

    return [$settings, $peer, $gateway];
}
