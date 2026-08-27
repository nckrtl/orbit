<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class ShowNodeCommand extends GatewayCommand
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

        $node = $this->send($connector, new ShowNodeRequest($nodeId), NodeResponse::class);

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $roles = $node->roles === [] ? '-' : implode(', ', $node->roles);
        $platform = match (true) {
            $node->platform !== null && $node->architecture !== null => "{$node->platform} ({$node->architecture})",
            $node->platform !== null => $node->platform,
            $node->architecture !== null => $node->architecture,
            default => '-',
        };

        $this->info("{$node->name}: {$node->status}");
        $this->line("Roles: {$roles}");
        $this->line('SSH: '.NodeOutput::sshEndpoint($node));
        $this->line('WireGuard: '.($node->wireguardAddress ?? '-'));
        $this->line('WireGuard public key: '.($node->wireguardPublicKey ?? '-'));
        $this->line('WireGuard endpoint override: '.($node->wireguardEndpointOverride ?? '-'));
        $this->line('DNS server override: '.($node->dnsServerOverride ?? '-'));
        $this->line('TLD: '.($node->tld ?? '-'));
        $this->line("Platform: {$platform}");
        $this->line('Access to: '.NodeOutput::accessList($node->access->canAccess ?? []));
        $this->line('Accessible by: '.NodeOutput::accessList($node->access->accessibleBy ?? []));

        if ($node->failedStep !== null || $node->errorCode !== null) {
            $failure = implode(' / ', array_filter([$node->failedStep, $node->errorCode], is_string(...)));
            $this->line("Failure: {$failure}");
        }

        $this->line("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
