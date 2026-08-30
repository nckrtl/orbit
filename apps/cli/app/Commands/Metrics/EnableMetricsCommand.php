<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;
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

        $status = $this->send($connector, new ShowMetricsStatusRequest, MetricsStatusResponse::class);
        if (! $status instanceof MetricsStatusResponse) {
            return self::FAILURE;
        }
        if ($status->assignment !== null) {
            return $this->renderGatewayFailure(
                'metrics.assignment_exists',
                'Metrics already has a non-terminal assignment.',
            );
        }

        $value = $this->argument('node');
        $nodes = null;
        if ($value === null && $this->input->isInteractive() && $this->option('json') !== true) {
            $nodes = $this->send($connector, new ListNodesRequest, NodesResponse::class);
            if (! $nodes instanceof NodesResponse) {
                return self::FAILURE;
            }
            $eligible = array_values(array_filter(
                $nodes->nodes,
                static fn ($node): bool => $node->status === 'active',
            ));
            $this->line('Eligible active nodes:');
            $this->table(['ID', 'Name', 'Roles'], array_map(static fn ($node): array => [
                $node->id,
                $node->name,
                $node->roles === [] ? '-' : implode(', ', $node->roles),
            ], $eligible));
            /** @var string|null $answer */
            $answer = $this->ask('Node ID or name');
            $value = $answer;
        }
        if ($value === null || $value === '') {
            return $this->validationFailure('node', 'Node ID or name is required.');
        }
        $nodeId = $this->resolveNodeId($connector, $value, $nodes);
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->send($connector, new EnableMetricsRequest($nodeId), MetricsMutationResponse::class);

        return $response instanceof MetricsMutationResponse ? $this->mutationOutput($response) : self::FAILURE;
    }
}
