<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

abstract class NodeCommand extends GatewayCommand
{
    #[\Override]
    protected function resolveNodeId(GatewayConnector $connector, mixed $reference, ?NodesResponse $nodes = null): ?int
    {
        if ($nodes === null && is_string($reference) && trim($reference) !== ''
            && preg_match('/\A-?[0-9]+\z/D', trim($reference)) !== 1) {
            $response = $this->sendWithProgress($connector, new ListNodesRequest, NodesResponse::class,
                ['Resolve Node', 'Resolving Node', 'Loaded Nodes']);

            if (! $response instanceof NodesResponse) {
                return null;
            }

            $nodes = $response;
        }

        return parent::resolveNodeId($connector, $reference, $nodes);
    }
}
