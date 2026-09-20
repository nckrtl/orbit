<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class ShowNodeCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:show
        {node : Numeric node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a node registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $node = $this->sendWithProgress($connector, new ShowNodeRequest($nodeId), NodeResponse::class, ['Show Node', 'Loading Node', 'Loaded Node']);

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $platform = implode(' ', array_filter([$node->platform, $node->architecture !== null ? "({$node->architecture})" : null]));
        $fields = [
            'ID' => $node->id,
            'Status' => $node->status,
            'Roles' => $node->roles,
            'SSH' => NodeOutput::sshEndpoint($node),
            'Cluster' => $node->clusterId,
            'WireGuard' => $node->wireguardIp,
            'LAN' => $node->lanIp,
            'WireGuard public key' => $node->wireguardPublicKey,
            'WireGuard endpoint override' => $node->wireguardEndpointOverride,
            'DNS server override' => $node->dnsServerOverride,
            'TLD' => NodeOutput::tld($node->tld),
            'Platform' => $platform,
            'OS' => $node->osVersion,
            'Apps path' => $node->settings?->apps?->path,
            'Access to' => NodeOutput::accessList($node->access->canAccess ?? []),
            'Accessible by' => NodeOutput::accessList($node->access->accessibleBy ?? []),
        ];

        if ($node->failedStep !== null || $node->errorCode !== null) {
            $fields['Failure'] = implode(' / ', array_filter([$node->failedStep, $node->errorCode], is_string(...)));
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Node: {$node->name}", $fields));
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
