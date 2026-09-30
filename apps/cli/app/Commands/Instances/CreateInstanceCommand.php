<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
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
        {--root= : Optional relative web-root override}
        {--domain= : Optional explicit Route domain}
        {--branch= : Optional explicit source branch}
        {--from= : Numeric source Instance id. Copy that development checkout instead of cloning the repository.}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a development Instance on an app-dev Node.';

    #[\Override]
    protected $help = <<<'HELP'
Creates a development Instance. New production Instances require a candidate. Use instance:clone.

A source Instance id copies another development Instance on the same Node instead of cloning the repository.
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

        $sourceInstanceId = $this->sourceInstanceId();

        if ($sourceInstanceId === false) {
            return self::FAILURE;
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
                root: $this->stringOption('root'),
                domain: $this->stringOption('domain'),
                branch: $this->stringOption('branch'),
                sourceInstanceId: $sourceInstanceId,
            ),
            InstanceResponse::class,
            $sourceInstanceId === null
                ? ['Create Instance', 'Creating Instance', 'Created Instance']
                : ['Copy Instance', 'Copying Instance', 'Copied Instance'],
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

    /**
     * The source Instance id from `--from`, null when the option is omitted, or false when it is not a positive integer.
     */
    private function sourceInstanceId(): int|false|null
    {
        $value = $this->option('from');

        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure('instance.id_invalid', 'Instance ID must be a positive integer.');

            return false;
        }

        return $id;
    }
}
