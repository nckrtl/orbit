<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\PromptAborted;
use App\Support\Console\Renderers\TableTheme;
use App\Support\Console\SearchableDataTablePrompt;
use Design\Support\TabbedShowPrompt;
use Design\Support\TabbedShowRenderer;
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

        $instance = $this->fixture('instances/instance-show/charlie-shop-dev')['data'];
        $processes = $this->fixture('processes/process-list/instance')['data'];

        $overview = $this->humanRenderer()->detail("App instance: {$instance['name']}", [
            'ID' => $instance['id'],
            'App' => $instance['app']['slug'],
            'Node' => $instance['node']['name'],
            'Status' => $instance['status'],
            'Environment' => $instance['environment'],
            'Source layout' => $instance['source_layout'],
            'Checkout' => $instance['checkout_path'],
            'Vite port' => $instance['vite_port'],
            'Root override' => $instance['root'],
            'Effective root' => $instance['effective_root'],
            'Selected branch' => $instance['selected_branch'],
            'Branch override' => $instance['branch_override'],
            'Migration required' => $instance['migration_required'] ? 'yes' : 'no',
            'Domain' => $instance['domain'],
            'URL' => $instance['url'],
        ]);

        $processRows = [];
        foreach ($processes as $process) {
            $processRows[$process['id']] = [(string) $process['id'], $process['name'], $process['runtime'], $process['desired_state'], $process['runtime_status'], $process['status']];
        }
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
                $prompt = new TabbedShowPrompt("App instance: {$instance['name']} on {$instance['node']['name']}", $tabs);
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
            $this->writeHumanMessage("Selected {$selection['tab']} row {$selection['key']}; the real command would open it here.");
        }

        return self::SUCCESS;
    }

    /** @return array{data: array<string, mixed>|list<array<string, mixed>>} */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 4).'/packages/php-sdk/fixtures/'.$name.'.json';
        if (! is_file($path)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $fixture['body'];
    }
}
