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
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
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
    /** Where the arrow keys lead from each pane while hovering. */
    private const array NEIGHBOURS = [
        'nodes' => ['down' => 'apps', 'right' => 'instances'],
        'apps' => ['up' => 'nodes', 'right' => 'processes'],
        'instances' => ['left' => 'nodes', 'down' => 'processes'],
        'processes' => ['up' => 'instances', 'right' => 'schedules', 'down' => 'firewall', 'left' => 'apps'],
        'schedules' => ['up' => 'instances', 'left' => 'processes', 'down' => 'firewall'],
        'firewall' => ['up' => 'processes', 'left' => 'apps'],
    ];

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

    /** The pane the arrows point at while hovering, and the one Enter focused. */
    private string $hover = 'nodes';

    private ?string $focus = null;

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
                        if ($event->char === 'r') {
                            $lastRefresh = 0;
                        }
                    }
                    if ($event instanceof CodedKeyEvent) {
                        $this->handleKey($event->code);
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

    private function handleKey(KeyCode $code): void
    {
        if ($this->focus === null) {
            // Hovering: the arrows walk the panes, Enter focuses the hovered one.
            $direction = match ($code) {
                KeyCode::Up => 'up',
                KeyCode::Down => 'down',
                KeyCode::Left => 'left',
                KeyCode::Right => 'right',
                default => null,
            };
            if ($direction !== null) {
                $this->hover = self::NEIGHBOURS[$this->hover][$direction] ?? $this->hover;
            }
            if ($code === KeyCode::Enter) {
                $this->focusOn($this->hover);
            }

            return;
        }

        match ($code) {
            KeyCode::Down => $this->move(1),
            KeyCode::Up => $this->move(-1),
            KeyCode::Esc => $this->focus = null,
            default => null,
        };
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
        if ($this->focus === null) {
            return;
        }
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
        $row = $this->focus === null ? null : $this->rowsFor($this->focus)[$this->selected[$this->focus]] ?? null;
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

        $instanceRows = array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['node']['name'], $i['domain']], $i['status'], $i['status'] !== 'active'), $instances);
        // A Process whose runtime disagrees with its desired state is the thing to inspect.
        $processRows = array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $this->rowsFor('processes'));
        $scheduleRows = array_map(fn (array $s): TableRow => $this->row([$s['name'], $s['expression'], $s['next_run']], $s['status'], $s['status'] !== 'enabled'), $this->rowsFor('schedules'));
        $firewallRows = array_map(fn (array $f): TableRow => $this->row([$f['port'], $f['action'], $f['source']], $f['status'], $f['status'] !== 'applied'), $this->rowsFor('firewall'));
        $nodeRows = array_map(fn (array $n): TableRow => TableRow::fromStrings($n['name']), $this->nodes);
        $appRows = array_map(fn (array $a): TableRow => TableRow::fromStrings($a['slug']), $this->apps);

        $left = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(count($this->nodes) + 2), Constraint::min(5))
            ->widgets(
                $this->pane('nodes', ' Nodes ', [], [Constraint::percentage(96)], $nodeRows),
                $this->pane('apps', ' Apps ', [], [Constraint::percentage(96)], $appRows),
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
                        $this->pane('processes', " Processes of {$instanceName} ", ['Name', 'Runtime', 'Status'], [Constraint::percentage(50), Constraint::percentage(24), Constraint::percentage(22)], $processRows),
                        $this->pane('schedules', " Schedules of {$instanceName} ", ['Name', 'Expression', 'Next run', 'Status'], [Constraint::percentage(32), Constraint::percentage(22), Constraint::percentage(24), Constraint::percentage(18)], $scheduleRows),
                    ),
                $this->pane('firewall', " Firewall on {$nodeName} ", ['Port', 'Action', 'Source', 'Status'], [Constraint::percentage(16), Constraint::percentage(12), Constraint::percentage(50), Constraint::percentage(18)], $firewallRows),
            );

        $enter = $this->enterCommand();
        $footer = ParagraphWidget::fromString($this->focus === null
            ? '  ←↑→↓ move between panes · Enter focus pane · r refresh · q leave'
            : '  ↑↓ move · Esc back to panes · q leave'.($enter !== '' ? "  │  Enter → {$enter}" : ''),
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
                    ->constraints(Constraint::length(24), Constraint::min(40))
                    ->widgets($left, $right),
                $footer,
            );
    }

    /**
     * A row whose status cell turns yellow when the record needs a look: yellow means "inspect".
     *
     * @param  list<string>  $cells
     */
    private function row(array $cells, string $status, bool $warn): TableRow
    {
        $statusCell = TableCell::fromString($status);
        if ($warn) {
            $statusCell->style = Style::default()->fg(AnsiColor::Yellow);
        }

        return TableRow::fromCells(...[...array_map(fn (string $cell): TableCell => TableCell::fromString($cell), $cells), $statusCell]);
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
        $hovered = $this->focus === null && $this->hover === $name;
        $block = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString($title))
            ->borderStyle(match (true) {
                $focused => Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD),
                $hovered => Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD),
                default => Style::default()->fg(AnsiColor::DarkGray),
            });

        if ($rows === []) {
            return $block->widget(ParagraphWidget::fromString(' None.')->style(Style::default()->fg(AnsiColor::DarkGray)));
        }

        $table = TableWidget::default();
        $table->columnSpacing = 1;

        if ($headers !== []) {
            $table->header(TableRow::fromStrings(...$headers));
        }

        return $block->widget(
            $table
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
