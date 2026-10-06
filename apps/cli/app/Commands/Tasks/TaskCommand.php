<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use App\Support\ExtensionCommandVisibility;
use App\Support\GatedExtensionCommand;
use Closure;
use DateTimeImmutable;
use JsonException;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextareaPrompt;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Responses\Projects\ProjectsResponse;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskAgentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCheckResponse;
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
abstract class TaskCommand extends GatewayCommand implements GatedExtensionCommand
{
    public function isHidden(): bool
    {
        return ExtensionCommandVisibility::listing()
            && app(GatewayExtensionState::class)->isEnabled('tasks') !== true;
    }

    public function extensionSlug(): ?string
    {
        return 'tasks';
    }

    protected function gatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        $state = app(GatewayExtensionState::class)->discover();

        if (! $state->isKnown()) {
            $this->failUnknownExtensionState('tasks', $state);

            return null;
        }

        return $this->coreGatewayConnector($repository, $connectors);
    }

    protected function coreGatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        return parent::gatewayConnector($repository, $connectors);
    }

    public const int TITLE_MAX = 160;

    public const int BRIEF_MAX = 8000;

    public const int COMMENT_BODY_MAX = 100_000;

    public const int AUTHOR_MAX = 255;

    /** The Gateway stores at most this many deliverables on a subtask. */
    public const int DELIVERABLES_MAX = 5;

    /** Set when a deliverables list is refused for a reason more specific than its shape. */
    protected static ?string $deliverablesRefusal = null;

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

        if (! is_string($value)) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", "{$label} is invalid.");

            return false;
        }

        $error = self::textError($value, $label, $max);

        if ($error !== null) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", $error);

            return false;
        }

        return $value;
    }

    /** Checks an optional change: null when absent, false after the refusal. */
    protected function optionalText(mixed $value, string $label, string $code, int $max): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", "{$label} is invalid.");

            return false;
        }

        $error = self::textError($value, $label, $max);

        if ($error !== null) {
            $this->renderGatewayFailure("tasks.{$code}_invalid", $error);

            return false;
        }

        return $value;
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

    /** @param (Closure(string): ?string)|null $validateValue */
    protected function promptText(string $label, int $max, bool $multiline = false, string $default = '', ?Closure $validateValue = null): string
    {
        $validate = static fn (string $value): ?string => self::textError($value, $label, $max) ?? ($validateValue !== null ? $validateValue($value) : null);
        $answer = $this->commandPrompts()->run(static fn (): TextPrompt|TextareaPrompt => $multiline
            ? new TextareaPrompt(TerminalText::safe($label), default: $default, required: "{$label} is required.", validate: $validate, rows: 8)
            : new TextPrompt(TerminalText::safe($label), default: $default, required: "{$label} is required.", validate: $validate));

        return is_string($answer) ? $answer : '';
    }

    protected static function deliverableIdError(string $value): ?string
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) === 1
            ? null : 'Deliverable ID must be lowercase letters, digits, and hyphens.';
    }

    protected function promptDeliverableId(): string
    {
        return $this->promptText('Deliverable', 64, validateValue: self::deliverableIdError(...));
    }

    protected function promptCheckId(): int
    {
        $value = $this->promptText('Check ID', 20, validateValue: static fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                ? 'Check ID must be a positive integer.' : null);

        return (int) $value;
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

            $rows[$group->id] = [(string) $group->id, $group->title, $group->project ?? (string) $group->projectId, $group->status];
        }

        return (int) $this->commandPrompts()->selectEntity('Task group', ['ID', 'Title', 'Project', 'Status'], $rows);
    }

    /**
     * Lists the group's subtasks and returns the selected ID.
     *
     * @param  list<string>  $statuses  Offer only these statuses; an empty list offers every subtask.
     */
    protected function selectSubtask(TaskGroupResponse $group, array $statuses = []): int
    {
        $rows = [];

        foreach ($group->tasks as $task) {
            if ($statuses !== [] && ! in_array($task->status, $statuses, true)) {
                continue;
            }

            $rows[$task->id] = [(string) $task->position, (string) $task->id, $task->title, $task->status];
        }

        return (int) $this->commandPrompts()->selectEntity('Subtask of '.$group->reference(), ['Position', 'ID', 'Title', 'Status'], $rows);
    }

    protected function selectProject(GatewayConnector $connector): ?int
    {
        $projects = $this->sendWithProgress(
            $connector,
            new ListProjectsRequest,
            ProjectsResponse::class,
            ['List Projects', 'Loading Projects', 'Loaded Projects'],
            dismiss: true,
        );

        if (! $projects instanceof ProjectsResponse) {
            return null;
        }

        $rows = [];

        foreach ($projects->projects as $project) {
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
            'Project' => $group->project ?? $group->projectId,
            'Status' => $group->status,
            'Assistance' => $group->assistanceRequested,
            'Kind' => self::askingKind($group->assistanceRequested, $group->assistanceKind),
            'Question' => self::askingText($group->assistanceRequested, $group->assistanceQuestion),
            'Reason' => self::askingText($group->assistanceRequested, $group->assistanceReason),
            'Instance' => $group->taskableId,
            'Pull request' => $group->prUrl,
            'Notify Coder' => $group->notifyCoder,
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
            self::askingKind($task->assistanceRequested, $task->assistanceKind),
            self::assistanceSummary($task->assistanceRequested, $task->assistanceKind, $task->assistanceQuestion, $task->assistanceReason),
        ], $group->tasks);

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Position', 'ID', 'Title', 'Status', 'Deliverables', 'Tokens', 'Line diff', 'Duration', 'Kind', 'Assistance'],
            $rows,
            'No subtasks.',
        ));
        $repros = [];

        foreach ($group->tasks as $task) {
            foreach ($task->deliverables as $deliverable) {
                if (($deliverable['fails_on_base'] ?? false) === true && is_string($deliverable['id'] ?? null)) {
                    $repros[] = $deliverable['id'];
                }
            }
        }

        if ($repros !== []) {
            $this->writeText('Fails on the start commit', implode(', ', $repros));
        }

        $this->writeHumanMessage("Request ID: {$group->requestId}");

        return self::SUCCESS;
    }

    /** @return array{int, int}|null */
    protected function resolveSubtaskTarget(GatewayConnector $connector, ?int $groupId, ?int $subtaskId): ?array
    {
        $groupId ??= $this->selectGroup($connector);
        if ($groupId === null) {
            return null;
        }
        if ($subtaskId === null) {
            $group = $this->loadGroup($connector, $groupId);
            if (! $group instanceof TaskGroupResponse) {
                return null;
            }
            $subtaskId = $this->selectSubtask($group);
        }

        return [$groupId, $subtaskId];
    }

    protected function renderCheck(TaskCheckResponse $check): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($check->toArray());

            return self::SUCCESS;
        }
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Check: {$check->id}", [
            'Kind' => $check->kind, 'Status' => $check->status, 'Exit code' => $check->exitCode,
            'Started' => self::time($check->startedAt), 'Finished' => self::time($check->finishedAt),
            'Failed step' => $check->failedStep, 'Changed paths' => $check->changedPaths,
            ...(is_string($check->deliverableEvidence['start'] ?? null) ? ['Start commit' => $check->deliverableEvidence['start']] : []),
        ]));
        if ($check->deliverableEvidence !== null) {
            $items = [];
            $commands = $check->deliverableEvidence['commands'] ?? [];
            if (is_array($commands)) {
                foreach ($commands as $id => $command) {
                    if (! is_array($command)) {
                        continue;
                    }
                    $label = is_string($command['id'] ?? null) ? $command['id'] : (string) $id;
                    $items[] = ['label' => $label, 'fields' => self::checkDisplayFields($command, [
                        'command' => 'Command', 'directory' => 'Directory', 'fails_on_base' => 'Must fail on base',
                        'paths' => 'Paths', 'passed' => 'Passed', 'exit_code' => 'Exit code',
                        'base_started' => 'Base started', 'base_exit_code' => 'Base exit code',
                        'base_timed_out' => 'Base timed out', 'base_timeout_seconds' => 'Base timeout (seconds)',
                    ])];
                }
            }
            if ($items !== []) {
                ConsoleWriter::write($this->output, $this->humanRenderer()->properties([
                    ['title' => 'Deliverable evidence', 'items' => $items],
                ]));
            }
        }
        if ($check->receipt !== null) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Receipt', self::checkDisplayFields($check->receipt, [
                'check_id' => 'Check ID', 'kind' => 'Kind', 'deliverable' => 'Deliverable', 'command' => 'Command',
                'directory' => 'Directory', 'managed_user' => 'Managed user', 'uid' => 'UID', 'tmpdir' => 'TMPDIR',
                'head' => 'HEAD', 'tree' => 'Tree', 'exit_code' => 'Exit code', 'base_exit_code' => 'Base exit code',
                'started_at' => 'Started', 'finished_at' => 'Finished',
            ])));
        }
        if ($check->outputTail !== null) {
            $this->writeText('Output tail', $check->outputTail);
        }
        $this->writeHumanMessage("Request ID: {$check->requestId}");

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $labels
     * @return array<string, scalar|null|list<scalar|null>>
     */
    private static function checkDisplayFields(array $data, array $labels): array
    {
        $fields = [];
        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (in_array($key, ['started_at', 'finished_at'], true) && is_string($value)) {
                $value = self::time($value);
            }
            if (is_scalar($value) || $value === null) {
                $fields[$label] = $value;
            } elseif (is_array($value) && array_is_list($value)) {
                $fields[$label] = array_values(array_filter($value, static fn (mixed $item): bool => is_scalar($item) || $item === null));
            }
        }

        return $fields;
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
            'Assistance' => $task->assistanceRequested,
            'Kind' => self::askingKind($task->assistanceRequested, $task->assistanceKind),
            'Question' => self::askingText($task->assistanceRequested, $task->assistanceQuestion),
            'Reason' => self::askingText($task->assistanceRequested, $task->assistanceReason),
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
                is_string($deliverable['id'] ?? null) ? $deliverable['id'] : '',
                is_string($deliverable['type'] ?? null) ? $deliverable['type'] : '',
                self::requirement($deliverable),
                is_string($deliverable['description'] ?? null) ? $deliverable['description'] : '',
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
     * @return list<array<string, string|bool|list<string>>>|false|null
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
            $this->renderGatewayFailure('tasks.deliverables_invalid', self::$deliverablesRefusal ?? self::deliverablesShapeMessage());

            return false;
        }

        return $deliverables;
    }

    /** The refusal for a deliverables file whose shape is wrong, before a more specific fails_on_base refusal. */
    protected static function deliverablesShapeMessage(): string
    {
        return 'The deliverables file must hold a JSON array of at most '.self::DELIVERABLES_MAX.' objects with string fields.';
    }

    /**
     * A list of at most DELIVERABLES_MAX objects with string fields, or null for any other value.
     * A command deliverable may set fails_on_base to a JSON boolean and paths to a list of strings.
     * Any other type, or any other value, is refused.
     *
     * @return list<array<string, string|bool|list<string>>>|null
     */
    protected static function deliverables(mixed $entries): ?array
    {
        self::$deliverablesRefusal = null;

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
                if (! is_string($field)) {
                    return null;
                }

                if ($field === 'fails_on_base') {
                    if (! is_bool($value)) {
                        self::$deliverablesRefusal = 'The fails_on_base value for '.self::deliverableWho(self::deliverableIdentity($entry)).' must be true or false.';

                        return null;
                    }

                    $fields[$field] = $value;

                    continue;
                }

                if ($field === 'paths') {
                    $paths = [];
                    foreach (is_array($value) && array_is_list($value) ? $value : [0] as $candidate) {
                        if (! is_string($candidate)) {
                            self::$deliverablesRefusal = 'The paths value for '.self::deliverableWho(self::deliverableIdentity($entry)).' must be a list of strings.';

                            return null;
                        }
                        $paths[] = $candidate;
                    }
                    $fields[$field] = $paths;

                    continue;
                }

                if (! is_string($value)) {
                    return null;
                }

                $fields[$field] = $value;
            }

            foreach (['fails_on_base', 'paths'] as $commandOnly) {
                if (array_key_exists($commandOnly, $fields) && ($fields['type'] ?? '') !== 'command') {
                    self::$deliverablesRefusal = 'The '.$commandOnly.' field is only allowed on a command deliverable ('.self::deliverableWho(self::deliverableIdentity($entry)).').';

                    return null;
                }
            }

            $deliverables[] = $fields;
        }

        return $deliverables;
    }

    /**
     * String keys are the only ones a deliverable identity can use. Other keys are ignored here
     * and refused by the field walk that called this.
     *
     * @param  array<mixed, mixed>  $deliverable
     * @return array<string, mixed>
     */
    private static function deliverableIdentity(array $deliverable): array
    {
        $identity = [];

        foreach ($deliverable as $key => $value) {
            if (is_string($key)) {
                $identity[$key] = $value;
            }
        }

        return $identity;
    }

    /** @param array<string, mixed> $deliverable */
    private static function deliverableWho(array $deliverable): string
    {
        $id = $deliverable['id'] ?? null;

        return is_string($id) && $id !== '' ? "deliverable {$id}" : 'this deliverable';
    }

    /** @param array<string, string|bool|list<string>> $deliverable */
    private static function requirement(array $deliverable): string
    {
        $field = static fn (string $key): string => is_string($deliverable[$key] ?? null) ? $deliverable[$key] : '';
        $test = rtrim($field('project'), '/').'/'.$field('file').': '.$field('name');

        if (($deliverable['fails_on_base'] ?? false) === true) {
            $test .= ' (fails on the start commit)';
        }

        return match ($field('type')) {
            'file' => $field('path').' ('.$field('change').')',
            'test' => $test,
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
            self::tokenMetric($agent->inputTokens),
            self::tokenMetric($agent->cachedInputTokens),
            self::tokenMetric($agent->outputTokens),
            self::tokenMetric($agent->modelCalls),
            self::tokenMetric($agent->peakContextTokens),
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

    /** The kind while the record is asking, or null so the cell is an em dash. */
    protected static function askingKind(bool $requested, ?string $kind): ?string
    {
        if (! $requested || $kind === null || $kind === '') {
            return null;
        }

        return $kind;
    }

    /** The text while the record is asking, or null so an old value stays an em dash. */
    protected static function askingText(bool $requested, ?string $text): ?string
    {
        if (! $requested || $text === null || $text === '') {
            return null;
        }

        return $text;
    }

    /**
     * The question for a direction request, otherwise the reason, while the record is asking.
     * Null hides an old value behind an em dash.
     */
    protected static function assistanceSummary(bool $requested, ?string $kind, ?string $question, ?string $reason): ?string
    {
        if (! $requested) {
            return null;
        }

        $primary = $kind === 'direction' ? $question : $reason;
        $fallback = $kind === 'direction' ? $reason : null;

        foreach ([$primary, $fallback] as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return 'yes';
    }

    protected static function tokens(?int $tokens): ?string
    {
        return $tokens === null ? null : number_format($tokens);
    }

    /**
     * A reported count, or a space so an unknown split stays a blank cell rather than an em dash.
     */
    protected static function tokenMetric(?int $value): string
    {
        return $value === null ? ' ' : number_format($value);
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
