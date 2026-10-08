<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\UpdateTaskGroupRequest;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class UpdateTaskGroupCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:update
        {group? : Numeric task group ID}
        {--title= : New title}
        {--brief= : New brief}
        {--status= : backlog or todo}
        {--preview : Keep the sandbox running during review}
        {--no-preview : Allow the sandbox to park during review}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change a task group\'s title, brief, status, or preview intent.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $title = $this->optionalText($this->option('title'), 'Title', 'title', self::TITLE_MAX);
        $brief = $title === false ? false : $this->optionalText($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);

        if ($title === false || $brief === false) {
            return self::FAILURE;
        }

        $status = $this->option('status');

        if ($status !== null && ! in_array($status, self::PLANNING_STATUSES, true)) {
            return $this->renderGatewayFailure('tasks.status_invalid', 'Status must be one of '.implode(', ', self::PLANNING_STATUSES).'.');
        }

        $status = is_string($status) ? $status : null;
        if ($this->option('preview') === true && $this->option('no-preview') === true) {
            return $this->renderGatewayFailure('tasks.preview_conflict', 'Use either --preview or --no-preview.');
        }
        $preview = $this->option('preview') === true ? true : ($this->option('no-preview') === true ? false : null);
        $changed = $title !== null || $brief !== null || $status !== null || $preview !== null;

        if (! $changed && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('tasks.update_required', 'Provide at least one task group update: --title, --brief, --status, --preview, or --no-preview.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $eligible = $preview !== null && $title === null && $brief === null && $status === null
            ? array_values(array_diff(self::GROUP_STATUSES, ['completed', 'failed', 'cancelled'])) : self::PLANNING_STATUSES;
        $groupId ??= $this->selectGroup($connector, $eligible);

        if ($groupId === null) {
            return self::FAILURE;
        }

        if (! $changed) {
            $current = $this->loadGroup($connector, $groupId);

            if (! $current instanceof TaskGroupResponse) {
                return self::FAILURE;
            }

            foreach ($this->promptFields(['title' => 'Title', 'brief' => 'Brief', 'status' => 'Status']) as $field) {
                match ($field) {
                    'title' => $title = $this->promptText('Title', self::TITLE_MAX, default: $current->title),
                    'brief' => $brief = $this->promptText('Brief', self::BRIEF_MAX, multiline: true, default: $current->brief),
                    default => $status = $this->promptChoice(
                        'Status',
                        ['backlog' => 'backlog', 'todo' => 'todo'],
                        in_array($current->status, self::PLANNING_STATUSES, true) ? $current->status : 'todo',
                    ),
                };
            }
        }

        $group = $this->sendWithProgress(
            $connector,
            new UpdateTaskGroupRequest($groupId, $title, $brief, $status, preview: $preview),
            TaskGroupResponse::class,
            ['Update task group', 'Updating task group', 'Updated task group'],
        );

        return $group instanceof TaskGroupResponse ? $this->renderGroup($group) : self::FAILURE;
    }
}
