<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;

final class EnableMetricsExporterCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:exporter:enable {node : Node ID or name} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Enable the Metrics exporter on a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));
        if ($nodeId === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new EnableMetricsExporterRequest($nodeId), MetricsMutationResponse::class);

        return $response instanceof MetricsMutationResponse ? $this->mutationOutput($response) : self::FAILURE;
    }
}
