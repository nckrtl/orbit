<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Metrics;

use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use JsonException;

/**
 * Reads one metrics snapshot from the Node over the same Gateway-to-Node SSH
 * execution path the sqlite inspector uses: `orbit internal:node-metrics`
 * runs on the Node and prints one JSON object. Kept synchronous and cheap;
 * the command's own internal sampling window is well under one second.
 */
final readonly class NodeMetricsSshReader implements NodeMetricsReader
{
    private const float COMMAND_TIMEOUT = 5.0;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function read(Node $node): array
    {
        $result = $this->ssh->execute(
            new SshConnection(
                host: (string) $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: self::COMMAND_TIMEOUT,
            ),
            new RemoteCommand(['orbit', 'internal:node-metrics'], timeout: self::COMMAND_TIMEOUT),
        );

        if (! $result->succeeded()) {
            $this->fail($node);
        }

        try {
            $decoded = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail($node);
        }

        if (! is_array($decoded)) {
            $this->fail($node);
        }

        return $decoded;
    }

    private function fail(Node $node): never
    {
        throw new ResourceOperationException(
            errorCode: 'node.metrics_unreachable',
            message: "Node [{$node->name}] metrics could not be read.",
            status: 502,
        );
    }
}
