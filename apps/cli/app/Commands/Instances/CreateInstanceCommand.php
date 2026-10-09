<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\NamedAppOptions;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

final class CreateInstanceCommand extends GatewayCommand
{
    use InstanceOutput;

    #[\Override]
    protected $signature = 'instance:create
        {project : Numeric Project ID}
        {node : Numeric node ID}
        {name : Instance name; default is reserved for the default development source}
        {--app-overrides= : JSON object of app path and web root overrides, keyed by app name}
        {--domain= : Optional explicit Route domain}
        {--branch= : Optional explicit source branch}
        {--database-server= : Database server that gets the new Instance database before setup}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a development Instance on an app-dev Node.';

    #[\Override]
    protected $help = <<<'HELP'
Creates a development Instance. New production Instances require a candidate. Use instance:clone.
With a Database server, Orbit creates the Instance's database there before the setup steps run.
HELP;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $projectId = $this->positiveId('project', 'Project', 'project.id_invalid');

        if ($projectId === null) {
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

        $overridesOption = $this->stringOption('app-overrides');
        $overrides = $overridesOption === null ? null : NamedAppOptions::overrides($overridesOption);

        if ($overridesOption !== null && $overrides === null) {
            return $this->renderGatewayFailure(
                'instance.app_overrides_invalid',
                'Pass --app-overrides as a JSON object keyed by app name, each with path and web_root.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->sendWithProgress(
            $connector,
            new CreateInstanceRequest(
                projectId: $projectId,
                nodeId: $nodeId,
                name: $name,
                appOverrides: $overrides,
                domain: $this->stringOption('domain'),
                branch: $this->stringOption('branch'),
                databaseServer: $this->stringOption('database-server'),
            ),
            InstanceResponse::class,
            ['Create Instance', 'Creating Instance', 'Created Instance'],
        );

        if (! $instance instanceof InstanceResponse) {
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
