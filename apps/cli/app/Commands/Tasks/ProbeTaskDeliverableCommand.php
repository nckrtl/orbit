<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ProbeTaskDeliverableRequest;
use Orbit\Sdk\Responses\Tasks\TaskCheckResponse;

final class ProbeTaskDeliverableCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:deliverable:probe
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {deliverable? : Declared command deliverable ID}
        {--base : Include the declared start-commit check}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Dry-run one declared command deliverable without a handoff.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        $subtaskId = $this->idArgument('subtask', 'Subtask', 'subtask');
        $deliverable = $this->textInput($this->argument('deliverable'), 'Deliverable', 'deliverable', 64);
        if ($groupId === false || $subtaskId === false || $deliverable === false) {
            return self::FAILURE;
        }
        if ($deliverable !== null && ($error = self::deliverableIdError($deliverable)) !== null) {
            return $this->renderGatewayFailure('tasks.deliverable_invalid', $error);
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $target = $this->resolveSubtaskTarget($connector, $groupId, $subtaskId);
        if ($target === null) {
            return self::FAILURE;
        }
        $deliverable ??= $this->promptDeliverableId();
        $check = $this->sendWithProgress($connector, new ProbeTaskDeliverableRequest($target[0], $target[1], $deliverable, $this->option('base') === true), TaskCheckResponse::class, ['Probe deliverable', 'Starting probe', 'Started probe']);

        return $check instanceof TaskCheckResponse ? $this->renderCheck($check) : self::FAILURE;
    }
}
