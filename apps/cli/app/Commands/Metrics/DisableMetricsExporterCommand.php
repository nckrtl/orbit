<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;

final class DisableMetricsExporterCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:exporter:disable {node : Numeric node ID} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Disable the Metrics exporter on a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');
        if ($nodeId === null) {
            return self::FAILURE;
        }
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $response = $this->send($connector, new DisableMetricsExporterRequest($nodeId), MetricsMutationResponse::class);

        return $response instanceof MetricsMutationResponse ? $this->mutationOutput($response) : self::FAILURE;
    }
}
