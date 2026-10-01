<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ListTaskDefinitionsRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionsResponse;

final class ListTaskDefinitionsCommand extends TaskDefinitionCommand
{
    #[\Override]
    protected $signature = 'tasks:definition:list
        {--project= : Only definitions of this numeric Project ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List task definitions.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $project = $this->option('project');
        $projectId = null;

        if ($project !== null) {
            $projectId = filter_var($project, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($projectId)) {
                return $this->renderGatewayFailure('tasks.project_invalid', 'Project ID must be a positive integer.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $definitions = $this->sendWithProgress(
            $connector,
            new ListTaskDefinitionsRequest($projectId),
            TaskDefinitionsResponse::class,
            ['List task definitions', 'Loading task definitions', 'Loaded task definitions'],
        );

        return $definitions instanceof TaskDefinitionsResponse ? $this->renderDefinitionList($definitions) : self::FAILURE;
    }
}
