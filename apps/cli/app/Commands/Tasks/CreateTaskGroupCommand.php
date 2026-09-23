<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use JsonException;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\SubtaskInput;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class CreateTaskGroupCommand extends TaskCommand
{
    /** The Gateway stores at most this many subtasks on create. */
    private const int MAX_SUBTASKS = 50;

    #[\Override]
    protected $signature = 'tasks:create
        {title? : Short name of the feature}
        {--project= : Numeric Project ID}
        {--brief= : Deliverables and acceptance}
        {--status= : backlog (default) or todo}
        {--subtasks= : JSON file with an ordered array of objects that each hold a title and a brief}
        {--notify-coder : Post the Coder settle webhook when the group settles}
        {--plan : Start a T3 planner that shapes the group in Backlog}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a task group in Backlog or Todo.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $status = $this->option('status');

        if ($status !== null && ! in_array($status, self::PLANNING_STATUSES, true)) {
            return $this->renderGatewayFailure('tasks.status_invalid', 'Status must be one of '.implode(', ', self::PLANNING_STATUSES).'.');
        }

        $subtasks = $this->subtasks();

        if ($subtasks === false) {
            return self::FAILURE;
        }

        $projectId = $this->idOption('project', 'Project', 'project');

        if ($projectId === false) {
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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $projectId ??= $this->selectProject($connector);

        if ($projectId === null) {
            return self::FAILURE;
        }

        $title ??= $this->promptText('Title', self::TITLE_MAX);
        $brief ??= $this->promptText('Brief', self::BRIEF_MAX, multiline: true);

        $group = $this->sendWithProgress(
            $connector,
            new CreateTaskGroupRequest(
                appId: $projectId,
                title: $title,
                brief: $brief,
                status: is_string($status) ? $status : null,
                notifyCoder: $this->option('notify-coder') === true ? true : null,
                plan: $this->option('plan') === true ? true : null,
                tasks: $subtasks,
            ),
            TaskGroupResponse::class,
            ['Create task group', 'Creating task group', 'Created task group'],
            static fn (object $group): ProgressState|ProgressOutcome => $group instanceof TaskGroupResponse && $group->status === 'failed'
                ? new ProgressOutcome(ProgressState::Warning, 'Created task group, but it failed to start')
                : ProgressState::Success,
        );

        return $group instanceof TaskGroupResponse ? $this->renderGroup($group) : self::FAILURE;
    }

    /**
     * Reads the --subtasks file. Returns null when the option is absent and false after the refusal.
     *
     * @return list<SubtaskInput>|false|null
     */
    private function subtasks(): array|false|null
    {
        $path = $this->option('subtasks');

        if ($path === null) {
            return null;
        }

        $refusal = 'The subtasks file must hold a JSON array of at most '.self::MAX_SUBTASKS.' objects, each with a title and a brief.';
        $contents = $path !== '' && is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->renderGatewayFailure('tasks.subtasks_invalid', 'The subtasks file cannot be read.');

            return false;
        }

        try {
            $entries = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $entries = null;
        }

        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > self::MAX_SUBTASKS) {
            $this->renderGatewayFailure('tasks.subtasks_invalid', $refusal);

            return false;
        }

        $subtasks = [];

        foreach ($entries as $entry) {
            $title = is_array($entry) ? ($entry['title'] ?? null) : null;
            $brief = is_array($entry) ? ($entry['brief'] ?? null) : null;

            if (
                ! is_string($title) || ! is_string($brief)
                || self::textError($title, 'Title', self::TITLE_MAX) !== null
                || self::textError($brief, 'Brief', self::BRIEF_MAX) !== null
            ) {
                $this->renderGatewayFailure('tasks.subtasks_invalid', $refusal);

                return false;
            }

            $subtasks[] = new SubtaskInput($title, $brief);
        }

        return $subtasks;
    }
}
