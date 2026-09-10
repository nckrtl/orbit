<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Environment\ImportAppInstanceEnvironmentRequest;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;

final class ImportEnvironmentCommand extends EnvironmentCommand
{
    #[\Override]
    protected $signature = 'env:import
        {--instance= : Positive AppInstance ID or exact Route hostname}
        {--replace : Replace stored-key conflicts while retaining other stored keys}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Import the workload environment file into stored AppInstance configuration.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $selector = $this->appInstanceSelector();

        if ($selector === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new ImportAppInstanceEnvironmentRequest(
                appInstance: $selector,
                replace: $this->option('replace') === true ? true : null,
            ),
            EnvironmentOperationResponse::class,
        );

        return $response instanceof EnvironmentOperationResponse
            ? $this->renderEnvironmentOperation($response)
            : self::FAILURE;
    }
}
