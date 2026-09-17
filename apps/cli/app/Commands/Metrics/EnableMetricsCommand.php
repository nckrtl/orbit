<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class EnableMetricsCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:enable {node? : Node ID or name} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable Metrics on one node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }

        $value = $this->argument('node');

        if ($value === null || $value === '') {
            $nodeId = $this->promptForNode($connector);
        } else {
            $nodeId = $this->resolveNodeId($connector, $value);
        }

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new EnableMetricsRequest($nodeId),
            MetricsMutationResponse::class,
            ['Enable Metrics', 'Enabling Metrics', 'Enabled Metrics'],
        );

        return $response instanceof MetricsMutationResponse ? $this->mutationOutput($response) : self::FAILURE;
    }

    private function promptForNode(GatewayConnector $connector): ?int
    {
        if (! $this->consoleMode()->mayPrompt) {
            $this->validationFailure('node', 'Node ID or name is required.');

            return null;
        }

        $nodes = $this->sendWithProgress(
            $connector,
            new ListNodesRequest,
            NodesResponse::class,
            ['List Nodes', 'Loading Nodes', 'Loaded Nodes'],
        );
        if (! $nodes instanceof NodesResponse) {
            return null;
        }

        $selected = $this->commandPrompts()->selectEntity('Node', ['ID', 'Name', 'Roles'], self::eligibleNodeRows($nodes));

        return is_int($selected) ? $selected : null;
    }

    /**
     * Eligible means the node can accept the Metrics role: active.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function eligibleNodeRows(NodesResponse $nodes): array
    {
        $eligible = array_filter(
            $nodes->nodes,
            static fn (NodeResponse $node): bool => $node->status === 'active',
        );

        $rows = [];
        foreach ($eligible as $node) {
            $rows[$node->id] = [
                (string) $node->id,
                $node->name,
                $node->roles === [] ? '—' : implode(', ', $node->roles),
            ];
        }

        return $rows;
    }
}
