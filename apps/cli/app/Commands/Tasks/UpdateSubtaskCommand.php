<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Tasks\UpdateSubtaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class UpdateSubtaskCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:subtask:update
        {group? : Numeric task group ID}
        {subtask? : Numeric subtask ID}
        {--title= : New title}
        {--brief= : New brief}
        {--position= : New position, starting at 1}
        {--deliverables= : JSON file with an array of typed deliverables that replaces the list}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Change a subtask\'s title, brief, position, or deliverables.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        $subtaskId = $groupId === false ? false : $this->idArgument('subtask', 'Subtask', 'subtask');

        if ($groupId === false || $subtaskId === false) {
            return self::FAILURE;
        }

        $title = $this->optionalText($this->option('title'), 'Title', 'title', self::TITLE_MAX);
        $brief = $title === false ? false : $this->optionalText($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);

        if ($title === false || $brief === false) {
            return self::FAILURE;
        }

        $position = $this->option('position');

        if ($position !== null) {
            $position = self::position($position);

            if ($position === null) {
                return $this->renderGatewayFailure('tasks.position_invalid', 'Position must be a positive integer.');
            }
        }

        $deliverables = $this->deliverablesFile();

        if ($deliverables === false) {
            return self::FAILURE;
        }

        $changed = $title !== null || $brief !== null || $position !== null || $deliverables !== null;

        if (! $changed && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('tasks.update_required', 'Provide at least one subtask update: --title, --brief, --position, or --deliverables.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        // The deliverables of a todo subtask change in any group status; everything else only in backlog.
        $onlyDeliverables = $deliverables !== null && $title === null && $brief === null && $position === null;
        $groupId ??= $this->selectGroup($connector, $onlyDeliverables ? [] : ['backlog']);

        if ($groupId === null) {
            return self::FAILURE;
        }

        if ($subtaskId === null || ! $changed) {
            $group = $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return self::FAILURE;
            }

            $subtaskId ??= $this->selectSubtask($group);

            if (! $changed) {
                $current = array_first(array_filter($group->tasks, static fn (SubtaskResponse $task): bool => $task->id === $subtaskId));

                foreach ($this->promptFields(['title' => 'Title', 'brief' => 'Brief', 'position' => 'Position']) as $field) {
                    match ($field) {
                        'title' => $title = $this->promptText('Title', self::TITLE_MAX, default: $current->title ?? ''),
                        'brief' => $brief = $this->promptText('Brief', self::BRIEF_MAX, multiline: true, default: $current->brief ?? ''),
                        default => $position = $this->promptPosition($current),
                    };
                }
            }
        }

        $task = $this->sendWithProgress(
            $connector,
            new UpdateSubtaskRequest($groupId, $subtaskId, $title, $brief, $position, $deliverables),
            SubtaskResponse::class,
            ['Update subtask', 'Updating subtask', 'Updated subtask'],
        );

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : self::FAILURE;
    }

    private function promptPosition(?SubtaskResponse $current): int
    {
        $answer = $this->commandPrompts()->run(static fn (): TextPrompt => new TextPrompt(
            'Position',
            default: $current === null ? '' : (string) $current->position,
            required: true,
            validate: static fn (string $value): ?string => self::position($value) === null ? 'Position must be a positive integer.' : null,
        ));

        return self::position($answer) ?? 1;
    }

    private static function position(mixed $value): ?int
    {
        $position = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($position) ? $position : null;
    }
}
