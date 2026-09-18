<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\RenameNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class RenameNodeCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:rename
        {node : Node ID or name}
        {name : New unique registry name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change the unique registry name of a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $name = $this->stringArgument('name', 'Name', 'node.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $node = $this->sendWithProgress(
            $connector,
            new RenameNodeRequest($nodeId, $name),
            NodeResponse::class,
            ['Rename Node', 'Renaming Node', 'Renamed Node'],
        );

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Node #{$node->id} renamed to [{$node->name}].");
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
