<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class ShowTaskGroupCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:show
        {group? : Numeric task group ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a task group with its brief and subtasks.';

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

        $groupId ??= $this->selectGroup($connector);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $group = $this->sendWithProgress($connector, new ShowTaskGroupRequest($groupId), TaskGroupResponse::class, ['Show task group', 'Loading task group', 'Loaded task group']);

        return $group instanceof TaskGroupResponse ? $this->renderGroup($group) : self::FAILURE;
    }
}
