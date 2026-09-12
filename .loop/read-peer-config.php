<?php

declare(strict_types=1);

use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

$gateway = '/home/orbit/orbit/apps/gateway';
require $gateway.'/vendor/autoload.php';
$app = require $gateway.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$repository = $app->make(VpnConfigurationRepository::class);
$result = [];
foreach (['app-dev', 'app-prod'] as $name) {
    $node = Node::query()->where('name', $name)->sole();
    $config = $repository->forPeer($node);
    if ($node->wireguard_endpoint_override !== null || $config->endpoint !== $argv[1].':51820') {
        throw new RuntimeException('Generated peer endpoint or unchanged override did not match the acquired topology.');
    }
    $result[$name] = [
        'endpoint' => $config->endpoint,
        'override' => $node->wireguard_endpoint_override,
        'public_ssh_host' => $node->public_ssh_host,
        'private_address' => $config->peerAddress,
        'dns_server' => $config->dnsServer,
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
