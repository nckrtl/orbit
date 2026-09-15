<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;

final class RemoveNodeCommand extends NodeCommand
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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $existing = $this->sendWithProgress($connector, new ShowNodeRequest($nodeId), NodeResponse::class, ['Resolve Node', 'Loading Node', 'Loaded Node']);

        if (! $existing instanceof NodeResponse) {
            return self::FAILURE;
        }

        if (! $this->confirmed($existing)) {
            return self::FAILURE;
        }

        $node = $this->sendWithProgress(
            $connector,
            // confirmed() only returns true after --force or an interactive "yes",
            // either of which is the consent the Gateway requires, so force is always true here.
            new RemoveNodeRequest($nodeId, force: true, offline: $this->option('offline') === true),
            RemovedNodeResponse::class,
            ['Remove Node', 'Removing Node', 'Removed Node'],
            static fn (object $response): ProgressState => NodeOutput::mutationState($response, removing: true),
        );

        if (! $node instanceof RemovedNodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Node [{$node->name}] removed.");
        ConsoleWriter::write($this->output, NodeOutput::degradationAdvisory(
            $this->humanRenderer(),
            $this->consoleMode(),
            $node->name,
            $node->degradation,
            $node->rolesShed,
            $node->retainedOnNode,
            $node->followUp,
        ));
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }

    private function confirmed(NodeResponse $node): bool
    {
        return $this->confirmAction(
            "Remove Node [{$node->name}] (#{$node->id}) from the Gateway?",
            'Node removal cancelled.',
            option: 'force',
            requiredCode: 'node.confirmation_required',
            requiredMessage: 'Use --force to confirm node removal.',
        );
    }
}
