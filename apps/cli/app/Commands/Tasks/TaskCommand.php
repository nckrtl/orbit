<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use DateTimeImmutable;
use JsonException;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextareaPrompt;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;

/**
 * Shared input resolution and rendering for the tasks family.
 *
 * An input helper returns the checked value, null when a terminal prompt must ask for it, or
 * false after it rendered the refusal. Prompts run only after every explicit value is checked,
 * so a refusal never waits on the Gateway.
 */
abstract class TaskCommand extends GatewayCommand
{
    public const int TITLE_MAX = 160;

    public const int BRIEF_MAX = 8000;

    public const int COMMENT_BODY_MAX = 100_000;

    public const int AUTHOR_MAX = 255;

    /** The Gateway stores at most this many deliverables on a subtask. */
    public const int DELIVERABLES_MAX = 5;

    /** @var list<string> */
    public const array GROUP_STATUSES = ['backlog', 'todo', 'reserved', 'running', 'reviewing', 'settling', 'completed', 'failed', 'cancelled'];

    /** @var list<string> */
    public const array PLANNING_STATUSES = ['backlog', 'todo'];

    protected function idArgument(string $argument, string $label, string $code): int|false|null
    {
        return $this->idValue($this->argument($argument), $label, $code);
    }

    protected function idOption(string $option, string $label, string $code): int|false|null
    {
        return $this->idValue($this->option($option), $label, $code);
    }

    protected function textInput(mixed $value, string $label, string $code, int $max): string|false|null
    {
        if ($value === null || $value === '') {
            return $this->promptable("tasks.{$code}_required", "{$label} is required.");
        }

        $error = is_string($value) ? self::textError($value, $label, $max) : "{$label} is invalid.";

        if ($error !== null) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", $error);

            return false;
        }

        return (string) $value;
    }

    /** Checks an optional change: null when absent, false after the refusal. */
    protected function optionalText(mixed $value, string $label, string $code, int $max): string|false|null
    {
        if ($value === null) {
            return null;
        }

        $error = is_string($value) ? self::textError($value, $label, $max) : "{$label} is invalid.";

        if ($error !== null) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", $error);

            return false;
        }

        return (string) $value;
    }

    /** @param list<string> $choices */
    protected function choiceInput(mixed $value, string $label, string $code, array $choices): string|false|null
    {
        if ($value === null || $value === '') {
            return $this->promptable("tasks.{$code}_required", "{$label} is required.");
        }

        if (! is_string($value) || ! in_array($value, $choices, true)) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", "{$label} must be one of ".implode(', ', $choices).'.');

            return false;
        }

        return $value;
    }

    protected function promptText(string $label, int $max, bool $multiline = false, string $default = ''): string
    {
        $validate = static fn (string $value): ?string => self::textError($value, $label, $max);
        $answer = $this->commandPrompts()->run(static fn (): TextPrompt|TextareaPrompt => $multiline
            ? new TextareaPrompt(TerminalText::safe($label), default: $default, required: "{$label} is required.", validate: $validate, rows: 8)
            : new TextPrompt(TerminalText::safe($label), default: $default, required: "{$label} is required.", validate: $validate));

        return is_string($answer) ? $answer : '';
    }

    /** @param array<string, string> $choices */
    protected function promptChoice(string $label, array $choices, ?string $default = null): string
    {
        $answer = $this->commandPrompts()->run(static fn (): SelectPrompt => new SelectPrompt(TerminalText::safe($label), $choices, default: $default));

        return is_string($answer) ? $answer : (string) array_key_first($choices);
    }

    /**
     * Asks which fields an update changes.
     *
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    protected function promptFields(array $fields): array
    {
        $answer = $this->commandPrompts()->run(static fn (): MultiSelectPrompt => new MultiSelectPrompt(
            'Fields to change',
            $fields,
            required: true,
            hint: 'Space selects, enter confirms.',
        ));

        return array_values(array_filter(is_array($answer) ? $answer : [], is_string(...)));
    }

    /**
     * Lists task groups and returns the selected ID.
     *
     * @param  list<string>  $statuses  Offer only these statuses; an empty list offers every group.
     * @param  list<string>  $excluded  Leave out these statuses.
     */
    protected function selectGroup(GatewayConnector $connector, array $statuses = [], array $excluded = []): ?int
    {
        $groups = $this->sendWithProgress(
            $connector,
            new ListTaskGroupsRequest(status: count($statuses) === 1 ? $statuses[0] : null),
            TaskGroupsResponse::class,
            ['List task groups', 'Loading task groups', 'Loaded task groups'],
            dismiss: true,
        );

        if (! $groups instanceof TaskGroupsResponse) {
            return null;
        }

        $rows = [];

        foreach ($groups->taskGroups as $group) {
            if (($statuses !== [] && ! in_array($group->status, $statuses, true)) || in_array($group->status, $excluded, true)) {
                continue;
            }

            $rows[$group->id] = [(string) $group->id, $group->title, $group->app ?? (string) $group->appId, $group->status];
        }

        return (int) $this->commandPrompts()->selectEntity('Task group', ['ID', 'Title', 'Project', 'Status'], $rows);
    }

    protected function selectSubtask(TaskGroupResponse $group): int
    {
        $rows = [];

        foreach ($group->tasks as $task) {
            $rows[$task->id] = [(string) $task->position, (string) $task->id, $task->title, $task->status];
        }

        return (int) $this->commandPrompts()->selectEntity('Subtask of '.$group->reference(), ['Position', 'ID', 'Title', 'Status'], $rows);
    }

    protected function selectProject(GatewayConnector $connector): ?int
    {
        $projects = $this->sendWithProgress(
            $connector,
            new ListAppsRequest,
            AppsResponse::class,
            ['List Projects', 'Loading Projects', 'Loaded Projects'],
            dismiss: true,
        );

        if (! $projects instanceof AppsResponse) {
            return null;
        }

        $rows = [];

        foreach ($projects->apps as $project) {
            $rows[$project->id] = [(string) $project->id, $project->name, $project->slug];
        }

        return (int) $this->commandPrompts()->selectEntity('Project', ['ID', 'Name', 'Slug'], $rows);
    }

    /** Reads a group for a prompt; the read never changes it. */
    protected function loadGroup(GatewayConnector $connector, int $groupId): ?TaskGroupResponse
    {
        $group = $this->sendWithProgress(
            $connector,
            new ShowTaskGroupRequest($groupId),
            TaskGroupResponse::class,
            ['Show task group', 'Loading task group', 'Loaded task group'],
            dismiss: true,
        );

        return $group instanceof TaskGroupResponse ? $group : null;
    }

    protected function renderGroup(TaskGroupResponse $group): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($group->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Task group: '.$group->reference(), [
            'ID' => $group->id,
            'Title' => $group->title,
            'Project' => $group->app ?? $group->appId,
            'Status' => $group->status,
            'Instance' => $group->taskableId,
            'Pull request' => $group->prUrl,
            'Notify Coder' => $group->notifyCoder,
            'Plan' => $group->plan,
            'Implementer model' => $group->implementerModel,
            'Reviewer model' => $group->reviewerModel,
            'Tokens' => self::tokens($group->tokens),
            'Line diff' => self::lineDiff($group->lineDiff, $group->linesAdded, $group->linesDeleted),
            'Duration' => self::duration($group->durationMs),
        ]));
        $this->writeText('Brief', $group->brief);

        $rows = array_map(static fn (SubtaskResponse $task): array => [
            $task->position,
            $task->id,
            $task->title,
            $task->status,
            count($task->deliverables),
            self::tokens($task->tokens),
            self::lineDiff($task->lineDiff, $task->linesAdded, $task->linesDeleted),
            self::duration($task->durationMs),
        ], $group->tasks);

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Position', 'ID', 'Title', 'Status', 'Deliverables', 'Tokens', 'Line diff', 'Duration'],
            $rows,
            'No subtasks.',
        ));
        $this->writeHumanMessage("Request ID: {$group->requestId}");

        return self::SUCCESS;
    }

    protected function renderSubtask(SubtaskResponse $task): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($task->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Subtask: {$task->id}", [
            'Task group' => $task->taskGroupId,
            'Position' => $task->position,
            'Title' => $task->title,
            'Status' => $task->status,
            'Type' => $task->type,
            'Implementer thread' => $task->implementerAgentThreadId,
            'Tokens' => self::tokens($task->tokens),
            'Line diff' => self::lineDiff($task->lineDiff, $task->linesAdded, $task->linesDeleted),
            'Duration' => self::duration($task->durationMs),
        ]));
        $this->writeText('Brief', $task->brief);
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Deliverable', 'Type', 'Requires', 'Description'],
            array_map(static fn (array $deliverable): array => [
                $deliverable['id'] ?? '',
                $deliverable['type'] ?? '',
                self::requirement($deliverable),
                $deliverable['description'] ?? '',
            ], $task->deliverables),
            'No deliverables.',
        ));
        $this->writeHumanMessage("Request ID: {$task->requestId}");

        return self::SUCCESS;
    }

    /**
     * Reads a JSON file with a list of deliverables. Returns null when the option is absent and false after the
     * refusal. The Gateway validates each deliverable's fields.
     *
     * @return list<array<string, string>>|false|null
     */
    protected function deliverablesFile(string $option = 'deliverables'): array|false|null
    {
        $path = $this->option($option);

        if ($path === null) {
            return null;
        }

        $contents = is_string($path) && $path !== '' && is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->renderGatewayFailure('tasks.deliverables_invalid', 'The deliverables file cannot be read.');

            return false;
        }

        try {
            $entries = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $entries = null;
        }

        $deliverables = self::deliverables($entries);

        if ($deliverables === null) {
            $this->renderGatewayFailure('tasks.deliverables_invalid', 'The deliverables file must hold a JSON array of at most '.self::DELIVERABLES_MAX.' objects with string fields.');

            return false;
        }

        return $deliverables;
    }

    /**
     * A list of at most DELIVERABLES_MAX objects with string fields, or null for any other value.
     *
     * @return list<array<string, string>>|null
     */
    protected static function deliverables(mixed $entries): ?array
    {
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > self::DELIVERABLES_MAX) {
            return null;
        }

        $deliverables = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || $entry === [] || array_is_list($entry)) {
                return null;
            }

            $fields = [];

            foreach ($entry as $field => $value) {
                if (! is_string($field) || ! is_string($value)) {
                    return null;
                }

                $fields[$field] = $value;
            }

            $deliverables[] = $fields;
        }

        return $deliverables;
    }

    /** @param array<string, string> $deliverable */
    private static function requirement(array $deliverable): string
    {
        $field = static fn (string $key): string => $deliverable[$key] ?? '';

        return match ($field('type')) {
            'file' => $field('path').' ('.$field('change').')',
            'test' => rtrim($field('project'), '/').'/'.$field('file').': '.$field('name'),
            'command' => $field('command').' in '.($field('directory') === '' ? '.' : $field('directory')),
            default => 'reviewer confirms',
        };
    }

    /** Writes one comment as a header line and its body indented by two spaces. */
    protected function writeComment(TaskCommentResponse $comment): void
    {
        $header = array_filter([
            self::time($comment->postedAt),
            $comment->type,
            'by '.$comment->author,
            $comment->reviewAttempt === null ? null : "review {$comment->reviewAttempt}",
            $comment->commitSha === null ? null : 'commit '.substr($comment->commitSha, 0, 12),
        ], static fn (?string $part): bool => $part !== null);

        $this->writeText(implode(' · ', $header), $comment->body);
    }

    /** @return list<scalar|null> */
    protected static function agentRow(TaskAgentResponse $agent): array
    {
        return [
            $agent->id,
            $agent->role,
            $agent->taskId,
            $agent->driver,
            $agent->model,
            $agent->state,
            self::tokens($agent->tokens),
            self::lineDiff(null, $agent->linesAdded, $agent->linesDeleted),
            self::time($agent->observedAt),
        ];
    }

    /** Writes a heading and multiline text, each line made safe and indented by two spaces. */
    protected function writeText(string $heading, string $text): void
    {
        if ($this->consoleMode()->machine) {
            return;
        }

        $width = max(1, $this->consoleMode()->columns - 2);
        $lines = [TerminalText::safe($heading)];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            foreach (TerminalText::wrap(TerminalText::safe($line), $width) as $part) {
                $lines[] = rtrim('  '.$part);
            }
        }

        ConsoleWriter::write($this->output, implode("\n", $lines)."\n\n");
    }

    protected static function textError(string $value, string $label, int $max): ?string
    {
        if (trim($value) === '') {
            return "{$label} is required.";
        }

        return mb_strlen($value) > $max ? "{$label} must be at most ".number_format($max).' characters.' : null;
    }

    protected static function tokens(?int $tokens): ?string
    {
        return $tokens === null ? null : number_format($tokens);
    }

    protected static function lineDiff(?int $total, ?int $added, ?int $deleted): ?string
    {
        if ($added !== null && $deleted !== null) {
            return "+{$added} -{$deleted}";
        }

        return $total === null ? null : (string) $total;
    }

    /** Shortens a Gateway timestamp to minutes in its own offset; an unreadable value stays as sent. */
    protected static function time(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $time === false ? $value : $time->format('Y-m-d H:i');
    }

    protected static function duration(?int $milliseconds): ?string
    {
        if ($milliseconds === null) {
            return null;
        }

        $seconds = intdiv($milliseconds, 1000);

        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => intdiv($seconds, 60).'m '.($seconds % 60).'s',
            default => intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m',
        };
    }

    private function idValue(mixed $value, string $label, string $code): int|false|null
    {
        if ($value === null || $value === '') {
            return $this->promptable("tasks.{$code}_required", "{$label} ID is required.");
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", "{$label} ID must be a positive integer.");

            return false;
        }

        return $id;
    }

    /** Returns null when a terminal prompt may ask for the omitted input, or false after the refusal. */
    private function promptable(string $code, string $message): ?false
    {
        if ($this->consoleMode()->mayPrompt) {
            return null;
        }

        $this->renderGatewayFailure($code, $message);

        return false;
    }
}
