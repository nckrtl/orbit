<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CreateSubtaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;

final class CreateSubtaskCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:subtask:create
        {group? : Numeric task group ID}
        {title? : Short name of the step}
        {--brief= : Goal and acceptance of the step}
        {--deliverables= : JSON file with an array of typed deliverables for the step}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Append a subtask to a task group.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $title = $this->textInput($this->argument('title'), 'Title', 'title', self::TITLE_MAX);

        if ($title === false) {
            return self::FAILURE;
        }

        $brief = $this->textInput($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);

        if ($brief === false) {
            return self::FAILURE;
        }

        $deliverables = $this->deliverablesFile();

        if ($deliverables === false) {
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

        $title ??= $this->promptText('Title', self::TITLE_MAX);
        $brief ??= $this->promptText('Brief', self::BRIEF_MAX, multiline: true);

        $task = $this->sendWithProgress($connector, new CreateSubtaskRequest($groupId, $title, $brief, $deliverables ?? []), SubtaskResponse::class, ['Create subtask', 'Creating subtask', 'Created subtask']);

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : self::FAILURE;
    }
}
