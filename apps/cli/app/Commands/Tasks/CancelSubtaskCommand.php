<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CancelSubtaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class CancelSubtaskCommand extends TaskCommand
{
    use ConfirmsTaskChanges;

    #[\Override]
    protected $signature = 'tasks:subtask:cancel
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {--yes : Confirm without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Cancel a running subtask and start the next one.';

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

        $groupId ??= $this->selectGroup($connector, ['running']);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $group = null;

        if ($subtaskId === null) {
            $group = $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return self::FAILURE;
            }

            $subtaskId = $this->selectSubtask($group, ['running']);
        }

        $consented = $this->consent(function () use ($connector, $groupId, $subtaskId, $group): ?string {
            $group ??= $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return null;
            }

            foreach ($group->tasks as $task) {
                if ($task->id === $subtaskId) {
                    return "Cancel subtask {$task->position} ({$task->title}) of task group {$group->reference()} and stop its implementer?";
                }
            }

            return "Cancel subtask {$subtaskId} of task group {$group->reference()} and stop its implementer?";
        }, "Subtask [{$subtaskId}] was not cancelled.");

        if (! $consented) {
            return self::FAILURE;
        }

        $task = $this->sendWithProgress($connector, new CancelSubtaskRequest($groupId, $subtaskId), SubtaskResponse::class, ['Cancel subtask', 'Cancelling subtask', 'Cancelled subtask']);

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : self::FAILURE;
    }
}
