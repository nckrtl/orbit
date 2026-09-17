<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use RuntimeException;

/**
 * Design sketch: an htop-like screen on php-tui that refreshes on a tick.
 *
 * Stats on top. Nodes and Apps on the left pick the scope: the focused selector decides
 * which App instances show on the right, the selected instance decides the Processes and
 * Schedules, and the Firewall follows the node. Every two seconds the sketch "fetches"
 * again and flips one Process status so the refresh is visible. The data is the recorded
 * fixtures plus made-up rows where no fixture exists. Registered only when ORBIT_DESIGN=1.
 */
final class TopFlowCommand extends GatewayCommand
{
    private const array PANES = ['nodes', 'apps', 'instances', 'processes', 'schedules', 'firewall'];

    #[\Override]
    protected $signature = 'design:top
        {--tick=2 : Seconds between refreshes}';

    #[\Override]
    protected $description = 'Design sketch of a live top-like screen; runs no Gateway request.';

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $apps = [];

    /** @var list<array<string, mixed>> */
    private array $instances = [];

    /** @var list<array<string, mixed>> */
    private array $processes = [];

    /** @var list<array<string, mixed>> */
    private array $schedules = [];

    /** @var list<array<string, mixed>> */
    private array $firewall = [];

    private string $focus = 'nodes';

    private string $scope = 'nodes';

    /** @var array<string, int> */
    private array $selected = ['nodes' => 0, 'apps' => 0, 'instances' => 0, 'processes' => 0, 'schedules' => 0, 'firewall' => 0];

    public function handle(): int
    {
        if (! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('input.invalid', 'The top screen needs an interactive terminal.');
        }

        $this->loadData();
        $tick = max(0.5, (float) ($this->stringOption('tick') ?? '2'));

        $terminal = Terminal::new();
        $display = DisplayBuilder::default()->fullscreen()->build();
        $terminal->enableRawMode();
        $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide());

        $refreshes = 0;
        $lastRefresh = microtime(true);

        try {
            while (true) {
                if (microtime(true) - $lastRefresh >= $tick) {
                    // The real screen would send the list requests here.
                    $refreshes++;
                    $lastRefresh = microtime(true);
                    $flip = $refreshes % 2 === 1;
                    $this->processes[1]['runtime_status'] = $flip ? 'stopped' : 'running';
                    $this->processes[1]['status'] = $flip ? 'degraded' : 'active';
                }

                while (($event = $terminal->events()->next()) !== null) {
                    if ($event instanceof CharKeyEvent) {
                        if ($event->char === 'q' || ($event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL)) {
                            break 2;
                        }
                        match ($event->char) {
                            'r' => $lastRefresh = 0,
                            'n' => $this->focusOn('nodes'),
                            'a' => $this->focusOn('apps'),
                            default => null,
                        };
                    }
                    if ($event instanceof CodedKeyEvent) {
                        match ($event->code) {
                            KeyCode::Tab => $this->focusOn(self::PANES[(array_search($this->focus, self::PANES, true) + 1) % count(self::PANES)]),
                            KeyCode::BackTab => $this->focusOn(self::PANES[(array_search($this->focus, self::PANES, true) + count(self::PANES) - 1) % count(self::PANES)]),
                            KeyCode::Down => $this->move(1),
                            KeyCode::Up => $this->move(-1),
                            KeyCode::Esc => throw new RuntimeException('leave'),
                            default => null,
                        };
                    }
                }

                $display->draw($this->screen($refreshes, $lastRefresh, $tick));
                usleep(100_000);
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'leave') {
                throw $exception;
            }
        } finally {
            $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }

        $this->writeHumanMessage("Left the top screen after {$refreshes} refreshes.");

        return self::SUCCESS;
    }

    private function focusOn(string $pane): void
    {
        $this->focus = $pane;
        if ($pane === 'nodes' || $pane === 'apps') {
            if ($this->scope !== $pane) {
                $this->selected['instances'] = 0;
            }
            $this->scope = $pane;
        }
    }

    private function move(int $step): void
    {
        $rows = count($this->rowsFor($this->focus));
        $before = $this->selected[$this->focus];
        $this->selected[$this->focus] = max(0, min(max(0, $rows - 1), $before + $step));

        // A new scope row or instance narrows the panes that depend on it.
        if ($this->selected[$this->focus] !== $before) {
            if ($this->focus === 'nodes' || $this->focus === 'apps') {
                $this->selected['instances'] = 0;
            }
            if ($this->focus !== 'processes' && $this->focus !== 'schedules' && $this->focus !== 'firewall') {
                $this->selected['processes'] = 0;
                $this->selected['schedules'] = 0;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function rowsFor(string $pane): array
    {
        return match ($pane) {
            'nodes' => $this->nodes,
            'apps' => $this->apps,
            'instances' => $this->scopedInstances(),
            'processes' => array_values(array_filter($this->processes, fn (array $process): bool => $process['target_id'] === $this->currentInstance()['id'])),
            'schedules' => array_values(array_filter($this->schedules, fn (array $schedule): bool => $schedule['instance_id'] === $this->currentInstance()['id'])),
            'firewall' => array_values(array_filter($this->firewall, fn (array $rule): bool => $rule['node'] === $this->currentNode()['name'])),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function scopedInstances(): array
    {
        if ($this->scope === 'apps') {
            $app = $this->apps[$this->selected['apps']];

            return array_values(array_filter($this->instances, fn (array $instance): bool => $instance['app']['slug'] === $app['slug']));
        }
        $node = $this->nodes[$this->selected['nodes']];

        return array_values(array_filter($this->instances, fn (array $instance): bool => $instance['node']['name'] === $node['name']));
    }

    /** @return array<string, mixed> */
    private function currentInstance(): array
    {
        return $this->scopedInstances()[$this->selected['instances']] ?? ['id' => 0, 'node' => ['name' => '']];
    }

    /** @return array<string, mixed> */
    private function currentNode(): array
    {
        if ($this->scope === 'nodes') {
            return $this->nodes[$this->selected['nodes']];
        }

        return ['name' => $this->currentInstance()['node']['name']];
    }

    /** The command Enter would run for the focused row; the status bar shows it. */
    private function enterCommand(): string
    {
        $row = $this->rowsFor($this->focus)[$this->selected[$this->focus]] ?? null;
        if ($row === null) {
            return '';
        }

        return match ($this->focus) {
            'nodes' => "orbit node:show {$row['name']}",
            'apps' => "orbit app:show {$row['slug']}",
            'instances' => "orbit instance:show {$row['app']['slug']}/{$row['name']}",
            'processes' => "orbit process:logs {$row['id']}",
            'schedules' => "orbit schedule:show {$row['id']}",
            'firewall' => "orbit firewall:show {$row['id']}",
            default => '',
        };
    }

    private function screen(int $refreshes, float $lastRefresh, float $tick): GridWidget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $bold = Style::default()->addModifier(Modifier::BOLD);
        $age = max(0, (int) round(microtime(true) - $lastRefresh));

        $instances = $this->scopedInstances();
        $instance = $this->currentInstance();
        $scopeName = $this->scope === 'apps' ? $this->apps[$this->selected['apps']]['slug'] : $this->nodes[$this->selected['nodes']]['name'];
        $instanceName = isset($instance['app']) ? "{$instance['app']['slug']}/{$instance['name']}" : '—';
        $nodeName = $this->scope === 'nodes' ? $scopeName : ($instance['node']['name'] ?? '—');

        $instanceRows = array_map(fn (array $i): TableRow => TableRow::fromStrings($i['app']['slug'], $i['name'], $i['environment'], $i['node']['name'], $i['domain'], $i['status']), $instances);
        $processRows = array_map(fn (array $p): TableRow => TableRow::fromStrings((string) $p['id'], $p['name'], $p['runtime'], $p['desired_state'], $p['runtime_status'], $p['status']), $this->rowsFor('processes'));
        $scheduleRows = array_map(fn (array $s): TableRow => TableRow::fromStrings((string) $s['id'], $s['name'], $s['expression'], $s['next_run'], $s['status']), $this->rowsFor('schedules'));
        $firewallRows = array_map(fn (array $f): TableRow => TableRow::fromStrings((string) $f['id'], $f['port'], $f['action'], $f['source'], $f['status']), $this->rowsFor('firewall'));
        $nodeRows = array_map(fn (array $n): TableRow => TableRow::fromStrings($n['name'], $n['status'], implode(', ', $n['roles'])), $this->nodes);
        $appRows = array_map(fn (array $a): TableRow => TableRow::fromStrings($a['slug'], (string) count(array_filter($this->instances, fn (array $i): bool => $i['app']['slug'] === $a['slug']))), $this->apps);

        $left = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(count($this->nodes) + 3), Constraint::min(5))
            ->widgets(
                $this->pane('nodes', ' Nodes ', ['Name', 'Status', 'Roles'], [Constraint::percentage(42), Constraint::percentage(24), Constraint::percentage(28)], $nodeRows),
                $this->pane('apps', ' Apps ', ['Slug', 'Instances'], [Constraint::percentage(68), Constraint::percentage(28)], $appRows),
            );

        $right = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::percentage(40), Constraint::percentage(35), Constraint::min(5))
            ->widgets(
                $this->pane('instances', $this->scope === 'apps' ? " Instances of {$scopeName} " : " Instances on {$scopeName} ", ['App', 'Name', 'Environment', 'Node', 'Domain', 'Status'], [Constraint::percentage(18), Constraint::percentage(11), Constraint::percentage(14), Constraint::percentage(11), Constraint::percentage(28), Constraint::percentage(11)], $instanceRows),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(55), Constraint::percentage(45))
                    ->widgets(
                        $this->pane('processes', " Processes of {$instanceName} ", ['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Status'], [Constraint::percentage(6), Constraint::percentage(20), Constraint::percentage(14), Constraint::percentage(14), Constraint::percentage(22), Constraint::percentage(14)], $processRows),
                        $this->pane('schedules', " Schedules of {$instanceName} ", ['ID', 'Name', 'Expression', 'Next run', 'Status'], [Constraint::percentage(8), Constraint::percentage(26), Constraint::percentage(22), Constraint::percentage(22), Constraint::percentage(12)], $scheduleRows),
                    ),
                $this->pane('firewall', " Firewall on {$nodeName} ", ['ID', 'Port', 'Action', 'Source', 'Status'], [Constraint::percentage(6), Constraint::percentage(14), Constraint::percentage(10), Constraint::percentage(44), Constraint::percentage(18)], $firewallRows),
            );

        $enter = $this->enterCommand();
        $footer = ParagraphWidget::fromString(
            '  Tab next pane · ↑↓ move · n nodes · a apps · r refresh · q leave'.($enter !== '' ? "  │  Enter → {$enter}" : ''),
        )->style($dim);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(3), Constraint::min(10), Constraint::length(1))
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::min(12), Constraint::length(60))
                    ->widgets(
                        ParagraphWidget::fromString('  orbit top')->style($bold),
                        ParagraphWidget::fromString("Gateway 10.44.0.1 · refreshed {$age}s ago · every {$tick}s · {$refreshes} refreshes  ")->style($dim)->alignment(HorizontalAlignment::Right),
                    ),
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)
                    ->widget(ParagraphWidget::fromString($this->stats())),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::length(34), Constraint::min(40))
                    ->widgets($left, $right),
                $footer,
            );
    }

    private function stats(): string
    {
        $count = fn (array $rows, string $key, string $value): int => count(array_filter($rows, fn (array $row): bool => $row[$key] === $value));

        return sprintf(
            ' Nodes %d · %d active     Apps %d     Instances %d · %d active · %d degraded     Processes %d · %d running     Schedules %d',
            count($this->nodes), $count($this->nodes, 'status', 'active'),
            count($this->apps),
            count($this->instances), $count($this->instances, 'status', 'active'), $count($this->instances, 'status', 'degraded'),
            count($this->processes), $count($this->processes, 'runtime_status', 'running'),
            count($this->schedules),
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<Constraint>  $widths
     * @param  list<TableRow>  $rows
     */
    private function pane(string $name, string $title, array $headers, array $widths, array $rows): Widget
    {
        $focused = $this->focus === $name;
        $block = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString($title))
            ->borderStyle($focused ? Style::default()->fg(AnsiColor::Cyan) : Style::default()->fg(AnsiColor::DarkGray));

        if ($rows === []) {
            return $block->widget(ParagraphWidget::fromString(' None.')->style(Style::default()->fg(AnsiColor::DarkGray)));
        }

        $table = TableWidget::default();
        $table->columnSpacing = 1;

        return $block->widget(
            $table
                ->header(TableRow::fromStrings(...$headers))
                ->widths(...$widths)
                ->rows(...$rows)
                ->select($this->selected[$name])
                ->highlightSymbol('› ')
                ->highlightStyle($focused ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()->addModifier(Modifier::BOLD)),
        );
    }

    /** Recorded fixtures where they exist; made-up rows fill the rest so every pane has data. */
    private function loadData(): void
    {
        $this->nodes = $this->fixture('nodes/node-list/default');
        $this->apps = $this->fixture('apps/app-list/many');
        $this->processes = $this->fixture('processes/process-list/instance');

        $recorded = $this->fixture('instances/instance-list/charlie-shop');
        $this->instances = array_map(fn (array $i): array => [...$i, 'domain' => $i['route']['domain'] ?? '—'], $recorded);

        $id = count($recorded);
        $processId = count($this->processes);
        foreach (array_slice($this->apps, 0, 9) as $index => $app) {
            if ($app['slug'] === 'charlie-shop') {
                continue;
            }
            foreach (['production', 'staging'] as $offset => $environment) {
                if ($offset === 1 && $index % 3 !== 0) {
                    continue;
                }
                $node = $this->nodes[($index + $offset) % count($this->nodes)];
                $name = $environment === 'production' ? 'main' : 'staging';
                $this->instances[] = [
                    'id' => ++$id, 'name' => $name, 'environment' => $environment,
                    'app' => ['id' => $app['id'], 'name' => $app['name'], 'slug' => $app['slug']],
                    'node' => ['id' => $node['id'], 'name' => $node['name']],
                    'domain' => ($environment === 'production' ? '' : 'staging.').$app['slug'].'.test',
                    'status' => $index === 4 ? 'degraded' : 'active',
                ];
                foreach (['queue', 'scheduler'] as $process) {
                    $this->processes[] = ['id' => ++$processId, 'target_id' => $id, 'name' => $process, 'runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'running', 'status' => 'active'];
                }
                $this->schedules[] = ['id' => count($this->schedules) + 1, 'instance_id' => $id, 'name' => 'backup', 'expression' => '0 3 * * *', 'next_run' => 'tomorrow 03:00', 'status' => 'enabled'];
            }
        }
        $this->schedules[] = ['id' => count($this->schedules) + 1, 'instance_id' => 1, 'name' => 'horizon-snapshot', 'expression' => '*/5 * * * *', 'next_run' => 'in 3 minutes', 'status' => 'enabled'];
        $this->schedules[] = ['id' => count($this->schedules) + 1, 'instance_id' => 1, 'name' => 'prune-logs', 'expression' => '0 4 * * 0', 'next_run' => 'Sunday 04:00', 'status' => 'disabled'];

        foreach ($this->nodes as $node) {
            $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '22/tcp', 'action' => 'allow', 'source' => '10.44.0.0/16', 'status' => 'applied'];
            $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '443/tcp', 'action' => 'allow', 'source' => 'any', 'status' => 'applied'];
            if (in_array('gateway', $node['roles'], true)) {
                $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '51820/udp', 'action' => 'allow', 'source' => 'any', 'status' => 'applied'];
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 4).'/packages/php-sdk/fixtures/'.$name.'.json';
        if (! is_file($path)) {
            throw new RuntimeException("Gateway fixture {$name} is not recorded.");
        }

        $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return array_values($fixture['body']['data']);
    }
}
