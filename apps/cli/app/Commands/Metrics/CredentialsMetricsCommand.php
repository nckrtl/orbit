<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;

final class CredentialsMetricsCommand extends MetricsCommand
{
    #[\Override]
    protected $signature = 'metrics:credentials {--reset : Reset the password} {--json : Return machine-readable JSON}';
    #[\Override]
    protected $description = 'Show Metrics credentials.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $request = $this->option('reset') === true
            ? new ResetMetricsCredentialsRequest
            : new ShowMetricsCredentialsRequest;
        $response = $this->send($connector, $request, MetricsCredentialsResponse::class);
        if (! $response instanceof MetricsCredentialsResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }
        $this->line("URL: {$response->url}");
        $this->line("Username: {$response->username}");
        $this->line("Password: {$response->password}");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
