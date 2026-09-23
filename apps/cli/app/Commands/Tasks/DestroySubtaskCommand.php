<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\DestroySubtaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class DestroySubtaskCommand extends TaskCommand
{
    use ConfirmsTaskChanges;

    #[\Override]
    protected $signature = 'tasks:subtask:destroy
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {--yes : Confirm without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Delete a subtask from a task group in Backlog.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        $subtaskId = $groupId === false ? false : $this->idArgument('subtask', 'Subtask', 'subtask');

        if ($groupId === false || $subtaskId === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groupId ??= $this->selectGroup($connector, ['backlog']);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $group = null;

        if ($subtaskId === null) {
            $group = $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return self::FAILURE;
            }

            $subtaskId = $this->selectSubtask($group);
        }

        $consented = $this->consent(function () use ($connector, $groupId, $subtaskId, $group): ?string {
            $group ??= $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return null;
            }

            foreach ($group->tasks as $task) {
                if ($task->id === $subtaskId) {
                    return "Destroy subtask {$task->position} ({$task->title}) of task group {$group->reference()}?";
                }
            }

            return "Destroy subtask {$subtaskId} of task group {$group->reference()}?";
        }, "Subtask [{$subtaskId}] was not destroyed.");

        if (! $consented) {
            return self::FAILURE;
        }

        $task = $this->sendWithProgress($connector, new DestroySubtaskRequest($groupId, $subtaskId), SubtaskResponse::class, ['Destroy subtask', 'Destroying subtask', 'Destroyed subtask']);

        if (! $task instanceof SubtaskResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($task->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Subtask [{$task->id}] {$task->title} destroyed.");
        $this->writeHumanMessage("Request ID: {$task->requestId}");

        return self::SUCCESS;
    }
}
