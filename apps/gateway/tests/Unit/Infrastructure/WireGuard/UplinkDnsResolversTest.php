<?php

declare(strict_types=1);

use App\Infrastructure\WireGuard\UplinkDnsResolvers;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('reads IPv4 nameservers from the systemd-resolved uplink file', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'run/systemd/resolve/resolv.conf' => "nameserver 192.0.2.53\nnameserver 192.0.2.54\nsearch internal\n",
    ]);

    try {
        expect($resolvers->nameservers())->toBe(['192.0.2.53', '192.0.2.54']);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('prefers the resolved uplink file over a DHCP lease', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'run/systemd/resolve/resolv.conf' => "nameserver 192.0.2.53\n",
        'proc/net/route' => uplink_default_route('eth0'),
        'sys/class/net/eth0/ifindex' => "2\n",
        'run/systemd/netif/leases/2' => "DNS=192.0.2.10 192.0.2.11\n",
    ]);

    try {
        expect($resolvers->nameservers())->toBe(['192.0.2.53']);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('reads DHCP nameservers for the default-route uplink interface', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'proc/net/route' => uplink_default_route('eth0'),
        'sys/class/net/eth0/ifindex' => "2\n",
        'run/systemd/netif/leases/2' => "# private\nADDRESS=192.0.2.20\nDNS=192.0.2.10 192.0.2.11\n",
    ]);

    try {
        expect($resolvers->nameservers())->toBe(['192.0.2.10', '192.0.2.11']);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('skips the stub resolver, loopback, and duplicate nameservers', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'run/systemd/resolve/resolv.conf' => implode("\n", [
            'nameserver 127.0.0.53',
            'nameserver 192.0.2.53',
            'nameserver 127.0.0.1',
            'nameserver 192.0.2.53',
            'nameserver 0.0.0.0',
            'nameserver 2001:db8::53',
            '',
        ]),
    ]);

    try {
        expect($resolvers->nameservers())->toBe(['192.0.2.53']);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('skips the WireGuard interface when choosing a DHCP uplink', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'proc/net/route' => uplink_route_table([
            ['orbit', '00000000', '50'],
            ['eth0',  '00000000', '100'],
        ]),
        'sys/class/net/orbit/ifindex' => "3\n",
        'sys/class/net/eth0/ifindex' => "2\n",
        'run/systemd/netif/leases/3' => "DNS=10.44.0.1\n",
        'run/systemd/netif/leases/2' => "DNS=192.0.2.10\n",
    ]);

    try {
        expect($resolvers->nameservers())->toBe(['192.0.2.10']);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('selects the lowest-metric default route for DHCP nameservers', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'proc/net/route' => uplink_route_table([
            ['eth0', '00000000', '200'],
            ['ens3', '00000000', '100'],
        ]),
        'sys/class/net/eth0/ifindex' => "2\n",
        'sys/class/net/ens3/ifindex' => "4\n",
        'run/systemd/netif/leases/2' => "DNS=192.0.2.20\n",
        'run/systemd/netif/leases/4' => "DNS=192.0.2.40\n",
    ]);

    try {
        expect($resolvers->nameservers())->toBe(['192.0.2.40']);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('keeps the documented public recursive fallback when no uplink resolvers are visible', function (): void {
    [$root, $resolvers] = uplink_dns_fixture([
        'run/systemd/resolve/resolv.conf' => "nameserver 127.0.0.53\noptions edns0 trust-ad\n",
    ]);

    try {
        expect($resolvers->nameservers())->toBe(UplinkDnsResolvers::FALLBACK);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

/**
 * @param  array<string, string>  $files
 * @return array{string, UplinkDnsResolvers}
 */
function uplink_dns_fixture(array $files): array
{
    $root = sys_get_temp_dir().'/orbit-uplink-dns-'.Str::uuid();
    $filesystem = new Filesystem;

    foreach ($files as $relative => $contents) {
        $path = $root.'/'.$relative;
        $filesystem->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);
    }

    return [$root, new UplinkDnsResolvers($root)];
}

function uplink_default_route(string $interface): string
{
    return uplink_route_table([[$interface, '00000000', '100']]);
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $routes
 */
function uplink_route_table(array $routes): string
{
    $lines = ["Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\tMTU\tWindow\tIRTT"];

    foreach ($routes as [$interface, $destination, $metric]) {
        $lines[] = "{$interface}\t{$destination}\t00000000\t0003\t0\t0\t{$metric}\t00000000\t0\t0\t0";
    }

    return implode("\n", $lines)."\n";
}
