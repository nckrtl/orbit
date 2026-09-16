<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
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

        $instance = $this->sendWithProgress(
            $connector,
            new UpdateAppInstanceRequest($instanceId, $branch),
            AppInstanceResponse::class,
            ['Update App instance', 'Updating App instance', 'Updated App instance'],
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("App instance: {$instance->name}", [
            'ID' => $instance->id,
            'Status' => $instance->status,
            'Deployment branch' => $branch,
            'Selected branch' => $instance->selectedBranch,
            'Request ID' => $instance->requestId,
        ]));

        return self::SUCCESS;
    }
}
