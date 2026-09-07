#!/usr/bin/env bash

set -euo pipefail

gateway=/home/orbit/orbit/apps/gateway
consumers=(
    "$gateway/app/Actions/Gateway/GatewayBootstrapIdentityValidator.php"
    "$gateway/app/Domain/WireGuard/WireGuardAddressAllocator.php"
    "$gateway/app/Infrastructure/WireGuard/NativeGatewayVpnConverger.php"
    "$gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php"
)

constructor_calls=$(grep -hoF 'Ipv4Subnet::from(' "${consumers[@]}" | wc -l)
test "$constructor_calls" -eq 4
if grep -Eq 'ip2long|subnetContains|usableRange|prefixLength\(string \$subnet' "${consumers[@]}"; then
    printf '%s\n' 'A copied IPv4 subnet parser remains in a consumer.' >&2
    exit 1
fi

expected_address=$(php -d assert.exception=1 -- "$gateway" <<'PHP'
<?php

declare(strict_types=1);

use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

function require_true(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$gateway = $argv[1];
require "{$gateway}/vendor/autoload.php";
$application = require "{$gateway}/bootstrap/app.php";
$application->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
});

foreach ([
    '10.0.0.0/8' => ['10.0.0.1', '10.255.255.254'],
    '10.44.0.0/24' => ['10.44.0.1', '10.44.0.254'],
    '10.44.0.0/30' => ['10.44.0.1', '10.44.0.2'],
] as $value => [$first, $last]) {
    $subnet = Ipv4Subnet::from($value);
    require_true($subnet->value() === $value, "Subnet [{$value}] was normalized.");
    require_true($subnet->usableRange() === [$first, $last], "Subnet [{$value}] has the wrong usable range.");
}

foreach (['10.44.0.1/24', '10.44.0.0/7', '10.44.0.0/31', '2001:db8::/64'] as $value) {
    try {
        Ipv4Subnet::from($value);
        require_true(false, "Invalid subnet [{$value}] was accepted.");
    } catch (InvalidArgumentException) {
    }
}

$small = Ipv4Subnet::from('10.44.0.0/30');
require_true($small->contains('10.44.0.0'), 'Network membership was lost.');
require_true($small->contains('10.44.0.3'), 'Broadcast membership was lost.');
require_true(! $small->containsUsableAddress('10.44.0.0'), 'The network address became usable.');
require_true(! $small->containsUsableAddress('10.44.0.3'), 'The broadcast address became usable.');
require_true(! $small->contains('10.44.0.4'), 'An outside address became a member.');
require_true(! $small->contains('2001:db8::53'), 'An IPv6 DNS address became an IPv4 member.');

$settings = $application->make(VpnSettings::class);
$persisted = Ipv4Subnet::from($settings->subnet());
$server = Node::query()
    ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn->value))
    ->first();
require_true($server instanceof Node, 'The real VPN server is missing.');
require_true(is_string($server->wireguard_ip), 'The real VPN server address is missing.');
require_true($persisted->containsUsableAddress($server->wireguard_ip), 'The real VPN server address is unusable.');

$peer = Node::query()
    ->whereKeyNot($server->getKey())
    ->whereNotNull('wireguard_ip')
    ->first();
require_true($peer instanceof Node, 'The real VPN peer is missing.');
$configuration = $application->make(VpnConfigurationRepository::class)->forPeer($peer);
require_true($configuration->subnet === $persisted->value(), 'The peer configuration changed the subnet wire value.');

$port = (int) $settings->port();
$endpoint = $settings->endpoint() ?? "{$server->public_ssh_host}:{$port}";
$dnsServer = $settings->dnsServer() ?? $server->wireguard_ip;
new GatewayBootstrapIdentityValidator()->validate(new BootstrapGatewayData(
    publicHost: $server->public_ssh_host,
    wireguardIp: $server->wireguard_ip,
    wireguardSubnet: $persisted->value(),
    wireguardEndpoint: $endpoint,
    dnsServer: $dnsServer,
    domain: $settings->domain(),
    privateInterface: $settings->privateInterface(),
    wireguardPort: $port,
));

try {
    $application->make(WireGuardAddressAllocator::class)->forProvisioning($persisted->networkAddress());
    require_true(false, 'The allocator accepted the network address.');
} catch (ResourceOperationException $exception) {
    require_true($exception->errorCode === 'vpn.peer_address_invalid', 'The allocator changed its boundary error.');
}

printf('%s/%d', $server->wireguard_ip, $persisted->prefixLength());
PHP
)

sudo grep -Fqx -- "Address = $expected_address" /etc/wireguard/orbit.conf

printf '%s\n' 'canonical-ipv4-subnet: ok'
