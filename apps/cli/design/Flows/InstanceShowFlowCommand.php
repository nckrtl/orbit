<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\PromptAborted;
use App\Support\Console\Renderers\TableTheme;
use App\Support\Console\SearchableDataTablePrompt;
use App\Support\NamedAppOptions;
use Design\Support\TabbedShowPrompt;
use Design\Support\TabbedShowRenderer;
use Orbit\Sdk\Responses\Projects\ProjectAppResponse;
use RuntimeException;

/**
 * Design sketch: instance:show as a tabbed screen with Overview, Processes, and Schedules.
 *
 * The data comes from the recorded fixtures under packages/php-sdk/fixtures, plus made-up
 * Schedules, so the sketch shows real shapes without a Gateway. Registered only when
 * ORBIT_DESIGN=1; see design/README.md.
 */
final class InstanceShowFlowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'design:instance-show
        {--tab=overview : Tab to open first: overview, processes, or schedules}';

    #[\Override]
    protected $description = 'Design sketch of a tabbed instance:show; runs no Gateway request.';

    public function handle(): int
    {
        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('input.invalid', 'The tabbed show needs an interactive terminal.');
        }

        $instance = $this->instanceSummary('instances/instance-show/charlie-shop-dev');
        $processRows = $this->processRows('processes/process-list/instance');

        $overview = $this->humanRenderer()->detail("Instance: {$instance['name']}", [
            'ID' => $instance['id'],
            'Project' => $instance['slug'],
            'Node' => $instance['node'],
            'Status' => $instance['status'],
            'Source layout' => $instance['source_layout'],
            'Checkout' => $instance['checkout_path'],
            'Vite port' => $instance['vite_port'],
            'Apps' => $instance['apps'] === [] ? null : $instance['apps'],
            'Selected branch' => $instance['selected_branch'],
            'Branch override' => $instance['branch_override'],
            'Domain' => $instance['domain'],
            'URL' => $instance['url'],
        ]);
        $scheduleRows = [
            1 => ['1', 'horizon-snapshot', '*/5 * * * *', 'in 3 minutes', 'enabled'],
            2 => ['2', 'backup', '0 3 * * *', 'tomorrow 03:00', 'enabled'],
            3 => ['3', 'prune-logs', '0 4 * * 0', 'Sunday 04:00', 'disabled'],
        ];

        TableTheme::extend([TabbedShowPrompt::class => TabbedShowRenderer::class]);

        try {
            // Prompts resolve their renderer from the active theme, so every prompt is built inside the run.
            $selection = $this->commandPrompts()->run(function () use ($overview, $processRows, $scheduleRows, $instance): TabbedShowPrompt {
                $tabs = [
                    ['title' => 'Overview', 'detail' => $overview],
                    ['title' => 'Processes', 'list' => new SearchableDataTablePrompt(['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Status'], $processRows, 'Processes', required: true, scroll: max(1, count($processRows)))],
                    ['title' => 'Schedules', 'list' => new SearchableDataTablePrompt(['ID', 'Name', 'Expression', 'Next run', 'Status'], $scheduleRows, 'Schedules', required: true, scroll: count($scheduleRows))],
                ];
                $prompt = new TabbedShowPrompt("Instance: {$instance['name']} on {$instance['node']}", $tabs);
                $prompt->active = match ($this->stringOption('tab')) {
                    'processes' => 1,
                    'schedules' => 2,
                    default => 0,
                };

                return $prompt;
            });
        } catch (PromptAborted|ConsoleInterrupted) {
            return self::SUCCESS;
        }

        if (is_array($selection)) {
            $tab = $selection['tab'] ?? null;
            $key = $selection['key'] ?? null;

            if (is_string($tab) && (is_int($key) || is_string($key))) {
                $this->writeHumanMessage("Selected {$tab} row {$key}; the real command would open it here.");
            }
        }

        return self::SUCCESS;
    }

    /** Recorded Gateway fixtures are the default. Tests point this at malformed files. */
    private ?string $fixtureRoot = null;

    public function useFixtureRoot(string $root): void
    {
        $this->fixtureRoot = rtrim($root, '/');
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     node: string,
     *     status: string,
     *     source_layout: string,
     *     checkout_path: string,
     *     vite_port: int|null,
     *     apps: list<string>,
     *     selected_branch: string|null,
     *     branch_override: string|null,
     *     domain: string|null,
     *     url: string|null
     * }
     */
    private function instanceSummary(string $name): array
    {
        $record = $this->fixtureObject($name);
        $project = $record['project'] ?? null;
        $node = $record['node'] ?? null;

        if (! is_array($project) || ! is_array($node)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        return [
            'id' => $this->fixtureInt($record, 'id', $name),
            'name' => $this->fixtureString($record, 'name', $name),
            'slug' => $this->fixtureString($this->stringKeyed($project, $name), 'slug', $name),
            'node' => $this->fixtureString($this->stringKeyed($node, $name), 'name', $name),
            'status' => $this->fixtureString($record, 'status', $name),
            'source_layout' => $this->fixtureString($record, 'source_layout', $name),
            'checkout_path' => $this->fixtureString($record, 'checkout_path', $name),
            'vite_port' => $this->fixtureNullableInt($record, 'vite_port', $name),
            'apps' => NamedAppOptions::describeAll(ProjectAppResponse::listFromGatewayData($record['apps'] ?? null)) ?? [],
            'selected_branch' => $this->fixtureNullableString($record, 'selected_branch', $name),
            'branch_override' => $this->fixtureNullableString($record, 'branch_override', $name),
            'domain' => $this->fixtureNullableString($record, 'domain', $name),
            'url' => $this->fixtureNullableString($record, 'url', $name),
        ];
    }

    /** @return array<int, list<string>> */
    private function processRows(string $name): array
    {
        $rows = [];

        foreach ($this->fixtureList($name) as $process) {
            $id = $this->fixtureInt($process, 'id', $name);
            $rows[$id] = [
                (string) $id,
                $this->fixtureString($process, 'name', $name),
                $this->fixtureString($process, 'runtime', $name),
                $this->fixtureString($process, 'desired_state', $name),
                $this->fixtureString($process, 'runtime_status', $name),
                $this->fixtureString($process, 'status', $name),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function fixtureObject(string $name): array
    {
        $data = $this->fixtureBody($name)['data'] ?? null;

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        return $this->stringKeyed($data, $name);
    }

    /** @return list<array<string, mixed>> */
    private function fixtureList(string $name): array
    {
        $data = $this->fixtureBody($name)['data'] ?? null;

        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $rows = [];

        foreach ($data as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new RuntimeException("Gateway fixture {$name} is not recorded.");
            }

            $rows[] = $this->stringKeyed($row, $name);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function fixtureBody(string $name): array
    {
        $root = $this->fixtureRoot ?? dirname(__DIR__, 4).'/packages/php-sdk/fixtures';
        $path = $root.'/'.$name.'.json';

        if (! is_file($path)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($fixture)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $body = $this->stringKeyed($fixture, $name)['body'] ?? null;

        if (! is_array($body)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        return $this->stringKeyed($body, $name);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value, string $name): array
    {
        $mapped = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("Gateway fixture {$name} is not recorded.");
            }

            $mapped[$key] = $item;
        }

        return $mapped;
    }

    /** @param  array<string, mixed>  $record */
    private function fixtureString(array $record, string $key, string $name): string
    {
        $value = $record[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $record */
    private function fixtureNullableString(array $record, string $key, string $name): ?string
    {
        if (! array_key_exists($key, $record) || $record[$key] === null) {
            return null;
        }

        return $this->fixtureString($record, $key, $name);
    }

    /** @param  array<string, mixed>  $record */
    private function fixtureInt(array $record, string $key, string $name): int
    {
        $value = $record[$key] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $record */
    private function fixtureNullableInt(array $record, string $key, string $name): ?int
    {
        if (! array_key_exists($key, $record) || $record[$key] === null) {
            return null;
        }

        return $this->fixtureInt($record, $key, $name);
    }
}
