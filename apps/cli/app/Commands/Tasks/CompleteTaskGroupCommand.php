<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CompleteTaskGroupRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class CompleteTaskGroupCommand extends TaskCommand
{
    use ConfirmsTaskChanges;

    #[\Override]
    protected $signature = 'tasks:complete
        {group? : Numeric task group ID}
        {--yes : Confirm without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Complete a settling task group and remove its Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groupId ??= $this->selectGroup($connector, ['settling']);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $consented = $this->consent(function () use ($connector, $groupId): ?string {
            $group = $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return null;
            }

            return $group->taskableId === null
                ? "Complete task group {$group->reference()} ({$group->title})?"
                : "Complete task group {$group->reference()} ({$group->title}) and remove Instance {$group->taskableId}?";
        }, "Task group [{$groupId}] was not completed.");

        if (! $consented) {
            return self::FAILURE;
        }

        $group = $this->sendWithProgress($connector, new CompleteTaskGroupRequest($groupId), TaskGroupResponse::class, ['Complete task group', 'Completing task group', 'Completed task group']);

        return $group instanceof TaskGroupResponse ? $this->renderGroup($group) : self::FAILURE;
    }
}
