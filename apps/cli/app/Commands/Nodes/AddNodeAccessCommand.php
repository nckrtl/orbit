<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\AddNodeAccessRequest;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;

final class AddNodeAccessCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:access:add
        {consumer : Numeric consumer node ID}
        {serving : Numeric serving node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Allow one node to run commands on another node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $consumerId = $this->positiveId('consumer', 'Consumer', 'node_access.consumer_id_invalid');

        if ($consumerId === null) {
            return self::FAILURE;
        }

        $servingId = $this->positiveId('serving', 'Serving', 'node_access.serving_id_invalid');

        if ($servingId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $access = $this->send(
            $connector,
            new AddNodeAccessRequest($consumerId, $servingId),
            AddedNodeAccessResponse::class,
        );

        if (! $access instanceof AddedNodeAccessResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($access->toArray());

            return self::SUCCESS;
        }

        $message = $access->alreadyExists
            ? "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) already exists."
            : "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) added.";

        $this->info($message);
        $this->line("Request ID: {$access->requestId}");

        return self::SUCCESS;
    }
}
