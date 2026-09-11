<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;

final class RollbackCommand extends DeploymentCommand
{
    #[\Override]
    protected $signature = 'instance:rollback
        {instance : Numeric instance ID}
        {--release= : Retained release name to select}
        {--json : Return machine-readable NDJSON}';

    #[\Override]
    protected $description = 'Select one retained production AppInstance release.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $release = $this->stringOption('release');

        if ($release === null) {
            return $this->renderGatewayFailure(
                'deployment.release_required',
                'A retained release name is required.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $stream = $this->send(
            $connector,
            new RollbackAppInstanceRequest($instanceId, $release),
            DeploymentStream::class,
        );

        return $stream instanceof DeploymentStream
            ? $this->renderDeploymentStream($stream)
            : self::FAILURE;
    }
}
