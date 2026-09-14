<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\UpdateAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class UpdateInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:update
        {instance : Numeric instance ID}
        {--branch= : Deployment branch}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a production AppInstance deployment branch.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $branch = $this->stringOption('branch');

        if ($branch === null) {
            return $this->renderGatewayFailure(
                'instance.branch_required',
                'Provide --branch to update the deployment branch.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send(
            $connector,
            new UpdateAppInstanceRequest($instanceId, $branch),
            AppInstanceResponse::class,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->info("{$instance->name} (#{$instance->id}): {$instance->status}");
        $this->line('Selected branch: '.($instance->selectedBranch ?? '-'));
        $this->line('Request ID: '.$instance->requestId);

        return self::SUCCESS;
    }
}
