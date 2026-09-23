<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;

final class ListTaskGroupsCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:list
        {--project= : Only groups of this numeric Project ID}
        {--status= : Only groups in this status}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List task groups.';

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

        $status = $this->option('status');

        if ($status !== null && ! in_array($status, self::GROUP_STATUSES, true)) {
            return $this->renderGatewayFailure('tasks.status_invalid', 'Status must be one of '.implode(', ', self::GROUP_STATUSES).'.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groups = $this->sendWithProgress(
            $connector,
            new ListTaskGroupsRequest($projectId, is_string($status) ? $status : null),
            TaskGroupsResponse::class,
            ['List task groups', 'Loading task groups', 'Loaded task groups'],
        );

        if (! $groups instanceof TaskGroupsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($groups->toArray());

            return self::SUCCESS;
        }

        $rows = array_map(static fn (TaskGroupResponse $group): array => [
            $group->id,
            $group->title,
            $group->app ?? $group->appId,
            $group->status,
            count(array_filter($group->tasks, static fn (SubtaskResponse $task): bool => $task->status === 'completed')).'/'.count($group->tasks),
        ], $groups->taskGroups);

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Title', 'Project', 'Status', 'Subtasks'],
            $rows,
            'No task groups.',
        ));
        $this->writeHumanMessage("Request ID: {$groups->requestId}");

        return self::SUCCESS;
    }
}
