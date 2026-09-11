<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;

final class DeployCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:deploy
        {instance : Numeric instance ID}
        {--json : Return machine-readable NDJSON}';

    #[\Override]
    protected $description = 'Deploy the configured branch of a production AppInstance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $stream = $this->send(
            $connector,
            new DeployAppInstanceRequest($instanceId),
            DeploymentStream::class,
        );

        return $stream instanceof DeploymentStream
            ? $this->renderDeploymentStream($stream)
            : self::FAILURE;
    }
}
