<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use JsonException;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Tasks\ListTaskDefinitionsRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionsResponse;

/**
 * Shared input checks and rendering for task definition commands.
 *
 * An input helper returns the checked value, null when a terminal prompt must ask for it, or
 * false after it rendered the refusal. The command that owns an option reads that option.
 */
abstract class TaskDefinitionCommand extends TaskCommand
{
    private const int NAME_MAX = 63;

    private const string NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    protected function definitionName(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            if ($this->consoleMode()->mayPrompt) {
                return null;
            }

            $this->renderGatewayFailure('tasks.definition_name_required', 'Definition name is required.');

            return false;
        }

        if (! is_string($value) || strlen($value) > self::NAME_MAX || preg_match(self::NAME_PATTERN, $value) !== 1) {
            $this->renderGatewayFailure(
                'tasks.definition_name_invalid',
                'Definition name must use lowercase letters, digits, and single hyphens, and be at most 63 characters.',
            );

            return false;
        }

        return $value;
    }

    /**
     * Reads a definition file supplied by the caller. Null when the option is absent, false after the refusal.
     */
    protected function suppliedDefinitionFile(mixed $path): string|false|null
    {
        if ($path === null) {
            if ($this->consoleMode()->mayPrompt) {
                return null;
            }

            $this->renderGatewayFailure('tasks.definition_file_required', 'Definition file is required.');

            return false;
        }

        if (! is_string($path)) {
            $this->renderGatewayFailure('tasks.definition_file_invalid', 'The definition file must contain a JSON object.');

            return false;
        }

        return $this->readDefinitionFile($path);
    }

    protected function promptDefinitionFile(): string|false
    {
        $answer = $this->commandPrompts()->run(static fn (): TextPrompt => new TextPrompt(
            TerminalText::safe('Definition file'),
            required: 'Definition file is required.',
            validate: static function (string $value): ?string {
                $contents = self::definitionFileContents($value);

                if ($contents === null) {
                    return 'The definition file cannot be read.';
                }

                return self::definitionObject($contents) === null ? 'The definition file must contain a JSON object.' : null;
            },
        ));

        return $this->readDefinitionFile(is_string($answer) ? $answer : '');
    }

    /**
     * A file name that is present and different from the argument is refused before the request.
     * A file that omits name keeps the argument; the Gateway uses the name in the path.
     */
    protected function definitionNameMatches(string $document, string $name): bool
    {
        $decoded = self::definitionObject($document);

        if (! is_array($decoded) || ! array_key_exists('name', $decoded) || $decoded['name'] === $name) {
            return true;
        }

        $this->renderGatewayFailure('tasks.definition_name_mismatch', 'The name in the definition file must match the definition name.');

        return false;
    }

    protected function selectDefinition(GatewayConnector $connector, int $projectId): ?string
    {
        $definitions = $this->sendWithProgress(
            $connector,
            new ListTaskDefinitionsRequest($projectId),
            TaskDefinitionsResponse::class,
            ['List task definitions', 'Loading task definitions', 'Loaded task definitions'],
            dismiss: true,
        );

        if (! $definitions instanceof TaskDefinitionsResponse) {
            return null;
        }

        $rows = [];

        foreach ($definitions->definitions as $definition) {
            if ($definition->projectId === $projectId) {
                $rows[$definition->name] = [$definition->name, $definition->title, $definition->status];
            }
        }

        return (string) $this->commandPrompts()->selectEntity('Task definition', ['Name', 'Title', 'Status'], $rows);
    }

    protected function renderDefinition(TaskDefinitionResponse $definition): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($definition->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Task definition: '.$definition->name, [
            'Project' => $definition->projectId,
            'Name' => $definition->name,
            'Title' => $definition->title,
            'Status' => $definition->status,
            'Schedule' => self::scheduleLabel($definition->schedule),
            'Parameters' => self::parameterLabels($definition->parameters),
            'Phases' => self::phaseLabels($definition->phases),
        ]));
        $this->writeText('Brief', $definition->brief);
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Key', 'Title', 'Kind', 'Phase'],
            array_map(static fn (array $subtask): array => [
                is_string($subtask['key'] ?? null) ? $subtask['key'] : '',
                is_string($subtask['title'] ?? null) ? $subtask['title'] : '',
                is_string($subtask['kind'] ?? null) ? $subtask['kind'] : '',
                is_string($subtask['phase'] ?? null) ? $subtask['phase'] : null,
            ], $definition->subtasks),
            'No subtasks.',
        ));
        $this->writeHumanMessage("Request ID: {$definition->requestId}");

        return self::SUCCESS;
    }

    protected function renderDefinitionList(TaskDefinitionsResponse $definitions): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($definitions->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Project', 'Name', 'Title', 'Status'],
            array_map(static fn (TaskDefinitionResponse $definition): array => [
                $definition->projectId,
                $definition->name,
                $definition->title,
                $definition->status,
            ], $definitions->definitions),
            'No task definitions were found.',
        ));
        $this->writeHumanMessage("Request ID: {$definitions->requestId}");

        return self::SUCCESS;
    }

    private function readDefinitionFile(string $path): string|false
    {
        $contents = self::definitionFileContents($path);

        if ($contents === null) {
            $this->renderGatewayFailure('tasks.definition_file_invalid', 'The definition file cannot be read.');

            return false;
        }

        if (self::definitionObject($contents) === null) {
            $this->renderGatewayFailure('tasks.definition_file_invalid', 'The definition file must contain a JSON object.');

            return false;
        }

        return $contents;
    }

    private static function definitionFileContents(string $path): ?string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * An associative decode turns `{}` into a list, so an empty object is recognized by its braces.
     *
     * @return array<string, mixed>|null
     */
    private static function definitionObject(string $contents): ?array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        if (array_is_list($decoded)) {
            return str_starts_with(ltrim($contents), '{') && $decoded === [] ? [] : null;
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $object[$key] = $value;
        }

        return $object;
    }

    /** @param array<string, mixed>|null $schedule */
    private static function scheduleLabel(?array $schedule): ?string
    {
        if ($schedule === null) {
            return null;
        }

        $cron = is_string($schedule['cron'] ?? null) ? $schedule['cron'] : '';
        $values = $schedule['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return $cron !== '' ? $cron : null;
        }

        $parts = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $parts[] = $key.'='.(is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR));
        }

        if ($parts === []) {
            return $cron !== '' ? $cron : null;
        }

        return ($cron !== '' ? $cron.' ' : '').'('.implode(', ', $parts).')';
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @return list<string>|null
     */
    private static function parameterLabels(array $parameters): ?array
    {
        $labels = [];

        foreach ($parameters as $parameter) {
            $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : '';
            $type = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
            $required = ($parameter['required'] ?? false) === true ? 'required' : 'optional';
            $labels[] = trim("{$name} {$type} {$required}");
        }

        return $labels === [] ? null : $labels;
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @return list<string>|null
     */
    private static function phaseLabels(array $phases): ?array
    {
        $labels = [];

        foreach ($phases as $phase) {
            $key = is_string($phase['key'] ?? null) ? $phase['key'] : '';
            $labels[] = ($phase['repeat'] ?? false) === true ? "{$key} repeat" : $key;
        }

        return $labels === [] ? null : $labels;
    }
}
