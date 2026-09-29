<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\SetupInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class SetupInstanceCommand extends GatewayCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:setup
        {instance : Numeric Instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run the Project setup steps for one development Instance.';

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

        $response = $this->sendWithProgress(
            $connector,
            new SetupInstanceRequest($instanceId),
            InstanceResponse::class,
            ['Run setup', 'Running setup', 'Ran setup'],
        );

        if (! $response instanceof InstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeInstanceDetails($response);

        return self::SUCCESS;
    }
}
