<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class CreateInstanceCommand extends GatewayCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:create
        {project : Numeric Project ID}
        {node : Numeric node ID}
        {name : Instance name; default is reserved for the default development source}
        {--root= : Optional relative web-root override}
        {--domain= : Optional explicit Route domain}
        {--branch= : Optional explicit source branch}
        {--recover-source-profile : Adopt complete source evidence for a legacy incomplete checkpoint}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a development Instance on an app-dev Node.';

    #[\Override]
    protected $help = <<<'HELP'
Creates a development Instance. New production Instances require a candidate. Use instance:clone.
HELP;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $appId = $this->positiveId('project', 'Project', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->stringArgument('name', 'Instance name', 'instance.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->sendWithProgress(
            $connector,
            new CreateAppInstanceRequest(
                appId: $appId,
                nodeId: $nodeId,
                name: $name,
                root: $this->stringOption('root'),
                domain: $this->stringOption('domain'),
                branch: $this->stringOption('branch'),
                recoverSourceProfile: $this->option('recover-source-profile') === true ? true : null,
            ),
            AppInstanceResponse::class,
            ['Create Instance', 'Creating Instance', 'Created Instance'],
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($instance->toArray());

            return self::SUCCESS;
        }

        $this->writeInstanceDetails($instance);

        return self::SUCCESS;
    }
}
