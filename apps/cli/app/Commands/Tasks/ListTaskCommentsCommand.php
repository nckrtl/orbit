<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class ListTaskCommentsCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:comment:list
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List a subtask\'s comments, newest first.';

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

        $groupId ??= $this->selectGroup($connector);

        if ($groupId === null) {
            return self::FAILURE;
        }

        if ($subtaskId === null) {
            $group = $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return self::FAILURE;
            }

            $subtaskId = $this->selectSubtask($group);
        }

        $comments = $this->sendWithProgress($connector, new ListTaskCommentsRequest($groupId, $subtaskId), TaskCommentsResponse::class, ['List comments', 'Loading comments', 'Loaded comments']);

        if (! $comments instanceof TaskCommentsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($comments->toArray());

            return self::SUCCESS;
        }

        if ($comments->comments === []) {
            $this->writeHumanMessage('No comments.');
        }

        foreach ($comments->comments as $comment) {
            $this->writeComment($comment);
        }

        $this->writeHumanMessage("Request ID: {$comments->requestId}");

        return self::SUCCESS;
    }
}
