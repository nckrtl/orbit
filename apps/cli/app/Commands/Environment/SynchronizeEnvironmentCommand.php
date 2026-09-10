<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Environment\SynchronizeAppInstanceEnvironmentRequest;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;

final class SynchronizeEnvironmentCommand extends EnvironmentCommand
{
    #[\Override]
    protected $signature = 'env:sync
        {--instance= : Positive AppInstance ID or exact Route hostname}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Synchronize stored AppInstance configuration to the workload environment file.';

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
            new SynchronizeAppInstanceEnvironmentRequest($selector),
            EnvironmentOperationResponse::class,
        );

        return $response instanceof EnvironmentOperationResponse
            ? $this->renderEnvironmentOperation($response)
            : self::FAILURE;
    }
}
