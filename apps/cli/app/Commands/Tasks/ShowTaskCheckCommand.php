<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ShowTaskCheckRequest;
use Orbit\Sdk\Responses\Tasks\TaskCheckResponse;

final class ShowTaskCheckCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:check:show
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {check? : Numeric check ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a task check ID, result, deliverable evidence, and output tail.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        $subtaskId = $this->idArgument('subtask', 'Subtask', 'subtask');
        $checkId = $this->idArgument('check', 'Check', 'check');
        if ($groupId === false || $subtaskId === false || $checkId === false) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $target = $this->resolveSubtaskTarget($connector, $groupId, $subtaskId);
        if ($target === null) {
            return self::FAILURE;
        }
        $checkId ??= $this->promptCheckId();
        $check = $this->sendWithProgress($connector, new ShowTaskCheckRequest($target[0], $target[1], $checkId), TaskCheckResponse::class, ['Show check', 'Loading check', 'Loaded check']);

        return $check instanceof TaskCheckResponse ? $this->renderCheck($check) : self::FAILURE;
    }
}
