<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;

final class RemoveNodeCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:remove
        {node : Numeric node ID}
        {--force : Skip the destructive confirmation prompt}
        {--offline : Shed roles and remove a node Orbit cannot reach}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a node registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        if (! $this->confirmed()) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $node = $this->send(
            $connector,
            // confirmed() only returns true after --force or an interactive "yes",
            // either of which is the consent the Gateway requires, so force is always true here.
            new RemoveNodeRequest($nodeId, force: true, offline: $this->option('offline') === true),
            RemovedNodeResponse::class,
        );

        if (! $node instanceof RemovedNodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->info("Node [{$node->name}] removed.");
        NodeOutput::degradationAdvisory(
            $this,
            $node->name,
            $node->degradation,
            $node->rolesShed,
            $node->retainedOnNode,
            $node->followUp,
        );
        $this->line("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }

    private function confirmed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if ($this->option('json') === true) {
            $this->renderGatewayFailure(
                'node.confirmation_required',
                'Use --force to confirm node removal.',
            );

            return false;
        }

        if ($this->input->isInteractive()) {
            return $this->confirm('Remove this node from the gateway?', false);
        }

        $this->renderGatewayFailure(
            'node.confirmation_required',
            'Use --force to confirm node removal.',
        );

        return false;
    }
}
