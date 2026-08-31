<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class WireGuardServerConfigRenderer
{
    /** @param Collection<int, Node> $nodes */
    public function render(VpnConfiguration $configuration, Collection $nodes): string
    {
        $lines = [
            '[Interface]',
            "Address = {$configuration->serverAddress}",
            "ListenPort = {$configuration->port}",
            "PrivateKey = {$configuration->serverPrivateKey}",
        ];

        foreach ($nodes->sortBy('id') as $node) {
            if (
                $node->is($configuration->server)
                || $node->wireguard_ip === null
                || $node->wireguard_public_key === null
            ) {
                continue;
            }

            $lines = [
                ...$lines,
                '',
                '[Peer]',
                "# {$node->name}",
                "PublicKey = {$node->wireguard_public_key}",
                "AllowedIPs = {$node->wireguard_ip}/32",
            ];
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
