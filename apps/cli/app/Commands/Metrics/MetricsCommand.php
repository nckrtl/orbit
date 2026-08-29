<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
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
        $this->line("Metrics operation completed for node #{$response->nodeId}: {$response->status}.");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    protected function validationFailure(string $field, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                    'details' => ['field' => $field],
                    'request_id' => null,
                ],
            ]);

            return self::FAILURE;
        }

        return $this->renderGatewayFailure('validation_failed', $message);
    }
}
