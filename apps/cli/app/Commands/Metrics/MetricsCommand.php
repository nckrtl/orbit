<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;

abstract class MetricsCommand extends GatewayCommand
{
    protected function connector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $factory,
    ): ?GatewayConnector {
        return $this->gatewayConnector($repository, $factory);
    }

    protected function mutationOutput(MetricsMutationResponse $response): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            "Metrics operation completed for node #{$response->nodeId}.",
            [
                'Node ID' => $response->nodeId,
                'Status' => $response->status,
                'Publication' => $response->publication,
                'Request ID' => $response->requestId,
            ],
        ));

        if ($response->publication === 'uncleaned') {
            $this->writeHumanMessage(
                'Publication not cleaned: no single active Gateway. The metrics.orbit route, certificate, and DNS record remain on the Gateway.',
            );
        }

        return self::SUCCESS;
    }

    protected function validationFailure(string $field, string $message): int
    {
        return $this->renderGatewayFailure(
            'metrics.node_required',
            $message,
            details: ['field' => $field],
        );
    }
}
