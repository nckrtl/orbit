<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class CreateTaskCommentCommand extends TaskCommand
{
    /** @var array<string, string> */
    private const array TYPES = [
        'assistance_requested' => 'assistance_requested: ask for help with a blocked subtask',
        'resolution' => 'resolution: answer a request and continue the agent',
    ];

    #[\Override]
    protected $signature = 'tasks:comment:create
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {--type= : assistance_requested or resolution}
        {--body= : Comment text}
        {--author= : Who wrote the comment}
        {--agent-thread= : Numeric Orbit agent thread ID the comment concerns}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Ask for assistance on a subtask or resolve a request.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        $subtaskId = $groupId === false ? false : $this->idArgument('subtask', 'Subtask', 'subtask');

        if ($groupId === false || $subtaskId === false) {
            return self::FAILURE;
        }

        $type = $this->choiceInput($this->option('type'), 'Comment type', 'comment_type', array_keys(self::TYPES));

        if ($type === false) {
            return self::FAILURE;
        }

        $body = $this->textInput($this->option('body'), 'Body', 'comment_body', self::COMMENT_BODY_MAX);

        if ($body === false) {
            return self::FAILURE;
        }

        $author = $this->textInput($this->option('author'), 'Author', 'comment_author', self::AUTHOR_MAX);

        if ($author === false) {
            return self::FAILURE;
        }

        $agentThreadId = null;

        if ($this->option('agent-thread') !== null) {
            $agentThreadId = filter_var($this->option('agent-thread'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($agentThreadId)) {
                return $this->renderGatewayFailure('tasks.agent_thread_invalid', 'Agent thread ID must be a positive integer.');
            }
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

        $type ??= $this->promptChoice('Comment type', self::TYPES);
        $body ??= $this->promptText('Body', self::COMMENT_BODY_MAX, multiline: true);
        $author ??= $this->promptText('Author', self::AUTHOR_MAX, default: self::localUser());

        $comment = $this->sendWithProgress(
            $connector,
            new CreateTaskCommentRequest($groupId, $subtaskId, $type, $body, $author, $agentThreadId),
            TaskCommentResponse::class,
            ['Create comment', 'Creating comment', 'Created comment'],
        );

        if (! $comment instanceof TaskCommentResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($comment->toArray());

            return self::SUCCESS;
        }

        $this->writeComment($comment);
        $this->writeHumanMessage("Request ID: {$comment->requestId}");

        return self::SUCCESS;
    }

    /** The operator's login name, offered as the prompt default. */
    private static function localUser(): string
    {
        foreach (['USER', 'USERNAME', 'LOGNAME'] as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '' && mb_strlen($value) <= self::AUTHOR_MAX) {
                return $value;
            }
        }

        return '';
    }
}
