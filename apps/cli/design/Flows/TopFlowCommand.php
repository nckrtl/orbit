<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
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
 * Schedules, and the Firewall follows the node. Enter on a row opens its detail page, a
 * right click (or `a`) lists the commands that take it, and the mouse selects rows and
 * panes. Every two seconds the sketch "fetches" again and flips one Process status so the
 * refresh is visible. The data is the recorded fixtures plus made-up rows where no fixture
 * exists. Registered only when ORBIT_DESIGN=1.
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

    private const int MENU_WIDTH = 36;

    /** Seconds between polls of a node's metrics; the lists refresh on their own tick. */
    private const int METRICS_TICK = 5;

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

    /** @var list<string> */
    private array $logs = [];

    /**
     * Made-up node_exporter-like readings per node, random-walked on the metrics tick.
     *
     * @var array<string, array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, load: array{float, float, float}, uptime: string, psi: array{cpu: float, mem: float, io: float}, disks: list<array{string, float, float}>}>
     */
    private array $metrics = [];

    private float $lastMetrics = 0;

    /** The pane the arrows point at while hovering, and the one Enter focused. */
    private string $hover = 'nodes';

    private ?string $focus = null;

    private string $scope = 'nodes';

    /** @var array<string, int> */
    private array $selected = ['nodes' => 0, 'apps' => 0, 'instances' => 0, 'processes' => 0, 'schedules' => 0, 'firewall' => 0];

    /**
     * The open action menu: which row it belongs to, its actions, the highlighted one, and where it floats.
     *
     * @var array{pane: string, title: string, row: array<string, mixed>, actions: array<string, string>, selected: int, at: array{int, int}|null}|null
     */
    private ?array $menu = null;

    /**
     * The record whose detail page is open.
     *
     * @var array{pane: string, row: array<string, mixed>}|null
     */
    private ?array $detail = null;

    /** The last action that ran, shown in the status bar. */
    private string $ran = '';

    /**
     * Where each pane was drawn in the last frame, for mouse hit-testing.
     *
     * @var array<string, array{area: Area, header: bool}>
     */
    private array $drawn = [];

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
        $terminal->execute(Actions::alternateScreenEnable(), Actions::cursorHide(), Actions::enableMouseCapture());

        $refreshes = 0;
        $lastRefresh = microtime(true);
        $this->lastMetrics = microtime(true);

        try {
            while (true) {
                if (microtime(true) - $this->lastMetrics >= self::METRICS_TICK) {
                    // The real screen would poll the node's metrics endpoint here.
                    $this->lastMetrics = microtime(true);
                    $this->walkMetrics();
                }
                if (microtime(true) - $lastRefresh >= $tick) {
                    // The real screen would send the list requests here.
                    $refreshes++;
                    $lastRefresh = microtime(true);
                    $flip = $refreshes % 2 === 1;
                    $this->processes[1]['runtime_status'] = $flip ? 'stopped' : 'running';
                    $this->logs[] = sprintf('%s  Processed job App\\Jobs\\SyncOrders #%d in %d ms', date('H:i:s'), 4200 + $refreshes, 40 + ($refreshes * 37) % 300);
                }

                while (($event = $terminal->events()->next()) !== null) {
                    if ($event instanceof CharKeyEvent) {
                        if ($event->char === 'q' || ($event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL)) {
                            break 2;
                        }
                        match ($event->char) {
                            'r' => $lastRefresh = 0,
                            'a' => $this->openMenu(),
                            default => null,
                        };
                    }
                    if ($event instanceof CodedKeyEvent) {
                        $this->handleKey($event->code);
                    }
                    if ($event instanceof MouseEvent) {
                        $this->handleMouse($event);
                    }
                }

                $display->draw($this->screen($refreshes, $lastRefresh, $tick, $display->viewportArea()));
                usleep(50_000);
            }
        } finally {
            $terminal->execute(Actions::disableMouseCapture(), Actions::cursorShow(), Actions::alternateScreenDisable());
            $terminal->disableRawMode();
        }

        $this->writeHumanMessage("Left the top screen after {$refreshes} refreshes.");

        return self::SUCCESS;
    }

    // ---- input -----------------------------------------------------------------------------

    private function handleKey(KeyCode $code): void
    {
        if ($this->menu !== null) {
            $count = count($this->menu['actions']);
            match ($code) {
                KeyCode::Down => $this->menu['selected'] = min($count - 1, $this->menu['selected'] + 1),
                KeyCode::Up => $this->menu['selected'] = max(0, $this->menu['selected'] - 1),
                KeyCode::Enter => $this->runAction(),
                KeyCode::Esc => $this->menu = null,
                default => null,
            };

            return;
        }

        if ($this->detail !== null) {
            match ($code) {
                KeyCode::Esc, KeyCode::Backspace, KeyCode::Left => $this->detail = null,
                KeyCode::Enter => $this->openMenu(),
                default => null,
            };

            return;
        }

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
            KeyCode::Enter => $this->openDetail(),
            KeyCode::Esc => $this->focus = null,
            default => null,
        };
    }

    private function handleMouse(MouseEvent $event): void
    {
        $x = $event->column;
        $y = $event->row;

        if ($this->menu !== null) {
            if ($event->kind !== MouseEventKind::Down) {
                return;
            }
            $item = $this->hitRow('menu', $x, $y);
            if ($item !== null && $item < count($this->menu['actions'])) {
                $this->menu['selected'] = $item;
                $this->runAction();
            } else {
                $this->menu = null;
            }

            return;
        }

        if ($this->detail !== null) {
            if ($event->kind === MouseEventKind::Down && $event->button === MouseButton::Right) {
                $this->openMenu([$x, $y]);
            }
            if ($event->kind === MouseEventKind::Down && $event->button === MouseButton::Left && $this->hitRow('back', $x, $y) !== null) {
                $this->detail = null;
            }

            return;
        }

        $pane = $this->paneAt($x, $y);
        if ($pane === null) {
            return;
        }

        if ($event->kind === MouseEventKind::ScrollDown || $event->kind === MouseEventKind::ScrollUp) {
            $this->focusOn($pane);
            $this->move($event->kind === MouseEventKind::ScrollDown ? 1 : -1);

            return;
        }
        if ($event->kind !== MouseEventKind::Down) {
            return;
        }

        // A click lands on a pane and, when it hits a row, selects that row.
        $this->hover = $pane;
        $this->focusOn($pane);
        $row = $this->hitRow($pane, $x, $y);
        if ($row !== null && $row < count($this->rowsFor($pane))) {
            $this->select($pane, $row);
        }
        if ($event->button === MouseButton::Right) {
            $this->openMenu([$x, $y]);
        }
    }

    private function paneAt(int $x, int $y): ?string
    {
        foreach ($this->drawn as $name => $drawn) {
            if ($name === 'menu' || $name === 'back') {
                continue;
            }
            $area = $drawn['area'];
            if ($x >= $area->left() && $x < $area->right() && $y >= $area->top() && $y < $area->bottom()) {
                return $name;
            }
        }

        return null;
    }

    /** The row index under a point inside a drawn pane: below its border and header, above its bottom border. */
    private function hitRow(string $name, int $x, int $y): ?int
    {
        $drawn = $this->drawn[$name] ?? null;
        if ($drawn === null) {
            return null;
        }
        $area = $drawn['area'];
        if ($x < $area->left() || $x >= $area->right()) {
            return null;
        }
        $first = $area->top() + 1 + ($drawn['header'] ? 1 : 0);
        if ($y < $first || $y >= $area->bottom() - 1) {
            return null;
        }

        return $y - $first;
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
        $this->select($this->focus, max(0, min(max(0, $rows - 1), $this->selected[$this->focus] + $step)));
    }

    private function select(string $pane, int $row): void
    {
        if ($this->selected[$pane] === $row) {
            return;
        }
        $this->selected[$pane] = $row;

        // A new scope row or instance narrows the panes that depend on it.
        if ($pane === 'nodes' || $pane === 'apps') {
            $this->selected['instances'] = 0;
        }
        if ($pane !== 'processes' && $pane !== 'schedules' && $pane !== 'firewall') {
            $this->selected['processes'] = 0;
            $this->selected['schedules'] = 0;
        }
    }

    private function openDetail(): void
    {
        if ($this->focus === null) {
            return;
        }
        $row = $this->rowsFor($this->focus)[$this->selected[$this->focus]] ?? null;
        if ($row !== null) {
            $this->detail = ['pane' => $this->focus, 'row' => $row];
        }
    }

    /**
     * Lists the commands that take the current record: the detail's, or the focused row.
     *
     * @param  array{int, int}|null  $at
     */
    private function openMenu(?array $at = null): void
    {
        $pane = $this->detail['pane'] ?? $this->focus;
        if ($pane === null) {
            return;
        }
        $row = $this->detail['row'] ?? $this->rowsFor($pane)[$this->selected[$pane]] ?? null;
        if ($row === null) {
            return;
        }

        $actions = match ($pane) {
            'nodes' => ['show' => "node:show {$row['name']}", 'doctor' => "node:doctor {$row['name']}", 'ssh' => "node:ssh {$row['name']}"],
            'apps' => ['show' => "app:show {$row['slug']}", 'deploy' => "app:deploy {$row['slug']}"],
            'instances' => ['show' => "instance:show {$row['app']['slug']}/{$row['name']}", 'deploy' => "instance:deploy {$row['app']['slug']}/{$row['name']}", 'logs' => "instance:logs {$row['app']['slug']}/{$row['name']}"],
            'processes' => [
                'logs' => "process:logs {$row['id']}",
                'restart' => "process:restart {$row['id']}",
                ...$row['runtime_status'] === 'running' ? ['stop' => "process:stop {$row['id']}"] : ['start' => "process:start {$row['id']}"],
            ],
            'schedules' => [
                'show' => "schedule:show {$row['id']}",
                'run now' => "schedule:run {$row['id']}",
                ...$row['status'] === 'enabled' ? ['disable' => "schedule:disable {$row['id']}"] : ['enable' => "schedule:enable {$row['id']}"],
            ],
            'firewall' => ['show' => "firewall:show {$row['id']}", 'remove' => "firewall:remove {$row['id']}"],
            default => [],
        };

        $this->menu = ['pane' => $pane, 'title' => $this->rowTitle($pane, $row), 'row' => $row, 'actions' => $actions, 'selected' => 0, 'at' => $at];
    }

    /** The sketch applies the state change locally; the real screen would send the command's request. */
    private function runAction(): void
    {
        if ($this->menu === null) {
            return;
        }
        $label = array_keys($this->menu['actions'])[$this->menu['selected']];
        $command = $this->menu['actions'][$label];
        $row = $this->menu['row'];

        if ($this->menu['pane'] === 'processes') {
            foreach ($this->processes as $index => $process) {
                if ($process['id'] !== $row['id']) {
                    continue;
                }
                $state = match ($label) {
                    'stop' => 'stopped',
                    'start', 'restart' => 'running',
                    default => null,
                };
                if ($state !== null) {
                    $this->processes[$index]['desired_state'] = $state;
                    $this->processes[$index]['runtime_status'] = $state;
                    if ($this->detail !== null && $this->detail['row']['id'] === $row['id']) {
                        $this->detail['row'] = $this->processes[$index];
                    }
                }
            }
        }
        if ($this->menu['pane'] === 'schedules') {
            foreach ($this->schedules as $index => $schedule) {
                if ($schedule['id'] === $row['id'] && in_array($label, ['enable', 'disable'], true)) {
                    $this->schedules[$index]['status'] = $label === 'enable' ? 'enabled' : 'disabled';
                }
            }
        }

        $this->ran = "orbit {$command}";
        $this->menu = null;
    }

    // ---- data ------------------------------------------------------------------------------

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

    /** @param  array<string, mixed>  $row */
    private function rowTitle(string $pane, array $row): string
    {
        return match ($pane) {
            'nodes' => $row['name'],
            'apps' => $row['slug'],
            'instances' => "{$row['app']['slug']}/{$row['name']}",
            'processes', 'schedules' => $row['name'],
            'firewall' => "{$row['port']} {$row['action']} {$row['source']}",
            default => '',
        };
    }

    /**
     * The properties a detail page lists, named as the show commands name them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function properties(string $pane, array $row): array
    {
        $value = fn (mixed $v): string => match (true) {
            $v === null, $v === '' => '—',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => implode(', ', $v),
            default => (string) $v,
        };
        $instance = fn (int $id): string => (function () use ($id): string {
            foreach ($this->instances as $instance) {
                if ($instance['id'] === $id) {
                    return "{$instance['app']['slug']}/{$instance['name']}";
                }
            }

            return '—';
        })();

        $properties = match ($pane) {
            'nodes' => ['Name' => $row['name'], 'Status' => $row['status'], 'Roles' => $row['roles'], 'Platform' => $row['platform'] ?? null, 'Architecture' => $row['architecture'] ?? null, 'TLD' => $row['tld'] ?? null, 'WireGuard IP' => $row['wireguard_ip'] ?? null, 'LAN IP' => $row['lan_ip'] ?? null, 'SSH host' => $row['public_ssh_host'] ?? null],
            'apps' => ['Name' => $row['name'], 'Slug' => $row['slug'], 'Repository' => $row['repository_url'] ?? null, 'Default branch' => $row['default_branch'] ?? null, 'Root' => $row['root'] ?? null],
            'instances' => ['Name' => $row['name'], 'App' => $row['app']['slug'], 'Node' => $row['node']['name'], 'Environment' => $row['environment'], 'Domain' => $row['domain'], 'Status' => $row['status'], 'Checkout' => $row['checkout_path'] ?? null, 'Selected branch' => $row['selected_branch'] ?? null],
            'processes' => ['Name' => $row['name'], 'Instance' => $instance($row['target_id']), 'Runtime' => $row['runtime'], 'Working directory' => $row['working_directory'] ?? null, 'Restart policy' => $row['restart_policy'] ?? null, 'Keep alive' => $row['keep_alive'] ?? null, 'Desired state' => $row['desired_state'], 'Runtime status' => $row['runtime_status'], 'Failed step' => $row['failed_step'] ?? null, 'Error code' => $row['error_code'] ?? null],
            'schedules' => ['Name' => $row['name'], 'Instance' => $instance($row['instance_id']), 'Expression' => $row['expression'], 'Next run' => $row['next_run'], 'Status' => $row['status']],
            'firewall' => ['Port' => $row['port'], 'Action' => $row['action'], 'Source' => $row['source'], 'Status' => $row['status'], 'Node' => $row['node']],
            default => [],
        };

        return array_map($value, $properties);
    }

    // ---- screens ---------------------------------------------------------------------------

    private function screen(int $refreshes, float $lastRefresh, float $tick, Area $area): Widget
    {
        $this->drawn = [];
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $age = max(0, (int) round(microtime(true) - $lastRefresh));

        $rows = Layout::default()->direction(Direction::Vertical)
            ->constraints([Constraint::length(1), Constraint::min(10), Constraint::length(1)])
            ->split($area);

        $header = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::min(12), Constraint::length(60))
            ->widgets(
                ParagraphWidget::fromString('  orbit top')->style(Style::default()->addModifier(Modifier::BOLD)),
                ParagraphWidget::fromString("Gateway 10.44.0.1 · refreshed {$age}s ago · every {$tick}s · {$refreshes} refreshes  ")->style($dim)->alignment(HorizontalAlignment::Right),
            );

        $body = $this->detail === null ? $this->dashboard($rows->get(1)) : $this->detailPage($rows->get(1));

        $footer = ParagraphWidget::fromString(match (true) {
            $this->menu !== null => '  ↑↓ choose · Enter or click runs · Esc closes',
            $this->detail !== null => '  Esc or ‹ back · a or right-click actions · q leave',
            $this->focus === null => '  ←↑→↓ or click picks a pane · Enter focuses · q leave',
            default => '  ↑↓ move · Enter opens · a or right-click actions · Esc back to panes · q leave',
        }.($this->ran !== '' ? "  │  Ran {$this->ran}" : ''))->style($dim);

        $screen = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(10), Constraint::length(1))
            ->widgets($header, $body, $footer);

        return $this->menu === null ? $screen : CompositeWidget::fromWidgets($screen, $this->menuPopup($area));
    }

    private function dashboard(Area $area): Widget
    {
        $appScope = $this->scope === 'apps';
        $instances = $this->scopedInstances();
        $instance = $this->currentInstance();
        $scopeName = $appScope ? $this->apps[$this->selected['apps']]['slug'] : $this->nodes[$this->selected['nodes']]['name'];
        $instancesTitle = $appScope ? " Instances of {$scopeName} " : " Instances on {$scopeName} ";
        $instanceName = isset($instance['app']) ? "{$instance['app']['slug']}/{$instance['name']}" : '—';
        $nodeName = ! $appScope ? $scopeName : ($instance['node']['name'] ?? '—');

        $metricsNode = ! $appScope ? $this->nodes[$this->selected['nodes']]['name'] : null;
        $metricsHeight = $metricsNode === null ? 0 : $this->metricsHeight($metricsNode);

        // The same splits the grid makes, kept so the mouse can find a pane and a row. The sidebar
        // (Nodes, Apps) runs the full height; stats, metrics, and the record panes fill the right.
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::length(24), Constraint::min(40)])->split($area);
        $left = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(count($this->nodes) + 2), Constraint::min(5)])->split($columns->get(0));
        $rightConstraints = [Constraint::length(3), Constraint::length($metricsHeight), Constraint::percentage(38), Constraint::percentage(32), Constraint::min(5)];
        $right = Layout::default()->direction(Direction::Vertical)->constraints($rightConstraints)->split($columns->get(1));
        $middle = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(55), Constraint::percentage(45)])->split($right->get(3));
        $this->drawn = [
            'nodes' => ['area' => $left->get(0), 'header' => false],
            'apps' => ['area' => $left->get(1), 'header' => false],
            'instances' => ['area' => $right->get(2), 'header' => true],
            'processes' => ['area' => $middle->get(0), 'header' => true],
            'schedules' => ['area' => $middle->get(1), 'header' => true],
            'firewall' => ['area' => $right->get(4), 'header' => true],
        ];

        $instanceRows = array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['node']['name'], $i['domain']], $i['status'], $i['status'] !== 'active'), $instances);
        // A Process whose runtime disagrees with its desired state is the thing to inspect.
        $processRows = array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $this->rowsFor('processes'));
        $scheduleRows = array_map(fn (array $s): TableRow => $this->row([$s['name'], $s['expression'], $s['next_run']], $s['status'], $s['status'] !== 'enabled'), $this->rowsFor('schedules'));
        $firewallRows = array_map(fn (array $f): TableRow => $this->row([$f['port'], $f['action'], $f['source']], $f['status'], $f['status'] !== 'applied'), $this->rowsFor('firewall'));
        $nodeRows = array_map(fn (array $n): TableRow => TableRow::fromStrings($n['name']), $this->nodes);
        $appRows = array_map(fn (array $a): TableRow => TableRow::fromStrings($a['slug']), $this->apps);

        $leftColumn = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(count($this->nodes) + 2), Constraint::min(5))
            ->widgets(
                $this->pane('nodes', ' Nodes ', [], [Constraint::percentage(96)], $nodeRows),
                $this->pane('apps', ' Apps ', [], [Constraint::percentage(96)], $appRows),
            );

        $rightColumn = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$rightConstraints)
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle(Style::default()->fg(AnsiColor::DarkGray))
                    ->widget(ParagraphWidget::fromString($this->stats())),
                $metricsNode === null ? BlockWidget::default() : $this->metricsPanel($metricsNode, $columns->get(1)->width),
                $this->pane('instances', $instancesTitle, ['App', 'Name', 'Environment', 'Node', 'Domain', 'Status'], [Constraint::percentage(18), Constraint::percentage(11), Constraint::percentage(14), Constraint::percentage(11), Constraint::percentage(28), Constraint::percentage(11)], $instanceRows),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(55), Constraint::percentage(45))
                    ->widgets(
                        $this->pane('processes', " Processes of {$instanceName} ", ['Name', 'Runtime', 'Status'], [Constraint::percentage(50), Constraint::percentage(24), Constraint::percentage(22)], $processRows),
                        $this->pane('schedules', " Schedules of {$instanceName} ", ['Name', 'Expression', 'Next run', 'Status'], [Constraint::percentage(32), Constraint::percentage(22), Constraint::percentage(24), Constraint::percentage(18)], $scheduleRows),
                    ),
                $this->pane('firewall', " Firewall on {$nodeName} ", ['Port', 'Action', 'Source', 'Status'], [Constraint::percentage(16), Constraint::percentage(12), Constraint::percentage(50), Constraint::percentage(18)], $firewallRows),
            );

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::length(24), Constraint::min(40))
            ->widgets($leftColumn, $rightColumn);
    }

    // ---- metrics ---------------------------------------------------------------------------

    private function metricsHeight(string $node): int
    {
        $m = $this->metrics[$node];

        // Core rows, then the taller of Mem+Swp and Disk+Load+Uptime+Pressure, inside the border.
        return intdiv(count($m['cores']) + 3, 4) + 4 + 2;
    }

    /** An htop-like block for the selected node: cores in four columns, then memory beside disk, load, and uptime. */
    private function metricsPanel(string $node, int $width): Widget
    {
        $m = $this->metrics[$node];
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $age = max(0, (int) round(microtime(true) - $this->lastMetrics));
        $inner = $width - 2;
        $column = intdiv($inner, 4);
        $half = intdiv($inner, 2);

        $coreLines = [];
        foreach (array_chunk($m['cores'], 4, true) as $group) {
            $spans = [];
            foreach ($group as $core => $load) {
                $spans = [...$spans, ...$this->bar(str_pad((string) $core, 3), $load, sprintf('%3.0f%%', $load * 100), $column - 2), Span::fromString('  ')];
            }
            $coreLines[] = Line::fromSpans(...$spans);
        }

        $left = [
            Line::fromSpans(...$this->bar('Mem', $m['mem'][0] / $m['mem'][1], sprintf('%.1fG/%.0fG', $m['mem'][0], $m['mem'][1]), $half - 2)),
            Line::fromSpans(...$this->bar('Swp', $m['swap'][1] > 0 ? $m['swap'][0] / $m['swap'][1] : 0, sprintf('%.1fG/%.0fG', $m['swap'][0], $m['swap'][1]), $half - 2)),
        ];

        [$mount, $used, $total] = $m['disks'][0];
        $right = [
            Line::fromSpans(...$this->bar(str_pad($mount, 3), $used / $total, sprintf('%.0fG/%.0fG', $used, $total), $half - 2, [80, 90])),
            Line::fromSpans(Span::styled('Load    ', $dim), Span::fromString(sprintf('%.2f  %.2f  %.2f', ...$m['load']))),
            Line::fromSpans(Span::styled('Uptime  ', $dim), Span::fromString($m['uptime'])),
            Line::fromSpans(Span::styled('Pressure', $dim), Span::fromString(sprintf('  cpu %.0f%%  mem %.0f%%  io %.0f%%', $m['psi']['cpu'], $m['psi']['mem'], $m['psi']['io'])), Span::styled('  some, 10 s', $dim)),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" {$node} · metrics · polled {$age}s ago · every ".self::METRICS_TICK.'s '))
            ->borderStyle($dim)
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(Constraint::length(count($coreLines)), Constraint::min(4))
                    ->widgets(
                        ParagraphWidget::fromText(Text::fromLines(...$coreLines)),
                        GridWidget::default()
                            ->direction(Direction::Horizontal)
                            ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                            ->widgets(
                                ParagraphWidget::fromText(Text::fromLines(...$left)),
                                ParagraphWidget::fromText(Text::fromLines(...$right)),
                            ),
                    ),
            );
    }

    /**
     * One htop-style bar: label, [|||||    ], and a reading; green, then yellow, then red past the thresholds.
     *
     * @param  array{int, int}  $thresholds  percentages where the bar turns yellow, then red
     * @return list<Span>
     */
    private function bar(string $label, float $ratio, string $reading, int $width, array $thresholds = [60, 85]): array
    {
        $ratio = max(0, min(1, $ratio));
        $inner = max(4, $width - strlen($label) - strlen($reading) - 3);
        $filled = (int) round($ratio * $inner);
        $colour = match (true) {
            $ratio * 100 >= $thresholds[1] => AnsiColor::Red,
            $ratio * 100 >= $thresholds[0] => AnsiColor::Yellow,
            default => AnsiColor::Green,
        };

        return [
            Span::styled($label.'[', Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled(str_repeat('|', $filled), Style::default()->fg($colour)),
            Span::fromString(str_repeat(' ', $inner - $filled)),
            Span::styled('] ', Style::default()->fg(AnsiColor::DarkGray)),
            Span::styled($reading, Style::default()->fg(AnsiColor::DarkGray)),
        ];
    }

    /** Nudges every reading a little so the panel visibly moves between polls. */
    private function walkMetrics(): void
    {
        $nudge = fn (float $value, float $step, float $min, float $max): float => max($min, min($max, $value + (mt_rand(-100, 100) / 100) * $step));
        foreach ($this->metrics as $node => $m) {
            $this->metrics[$node]['cores'] = array_map(fn (float $c): float => $nudge($c, 0.12, 0.01, 0.99), $m['cores']);
            $this->metrics[$node]['mem'][0] = $nudge($m['mem'][0], 0.3, 0.5, $m['mem'][1] - 0.2);
            $this->metrics[$node]['load'] = [$nudge($m['load'][0], 0.3, 0.05, 12), $nudge($m['load'][1], 0.15, 0.05, 12), $nudge($m['load'][2], 0.05, 0.05, 12)];
            foreach (['cpu', 'mem', 'io'] as $key) {
                $this->metrics[$node]['psi'][$key] = $nudge($m['psi'][$key], 3, 0, 100);
            }
            $this->metrics[$node]['disks'] = array_map(fn (array $d): array => [$d[0], $nudge($d[1], 0.4, 1, $d[2]), $d[2]], $m['disks']);
        }
    }

    /** One record: its properties on the left, and for a Process its recent log lines on the right. */
    private function detailPage(Area $area): Widget
    {
        $detail = $this->detail ?? throw new RuntimeException('No detail is open.');
        $pane = $detail['pane'];
        $row = $detail['row'];
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        $rows = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(5)])->split($area);
        $this->drawn = ['back' => ['area' => Area::fromScalars($rows->get(0)->left(), $rows->get(0)->top() - 1, 12, 3), 'header' => false]];

        $kind = match ($pane) {
            'nodes' => 'Node',
            'apps' => 'App',
            'instances' => 'App instance',
            'processes' => 'Process',
            'schedules' => 'Schedule',
            'firewall' => 'Firewall rule',
            default => '',
        };
        $crumbs = Line::fromSpans(
            Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)),
            Span::styled("{$kind}: ", $dim),
            Span::styled($this->rowTitle($pane, $row), Style::default()->addModifier(Modifier::BOLD)),
        );

        $propertyRows = [];
        foreach ($this->properties($pane, $row) as $name => $value) {
            $warn = in_array($name, ['Runtime status', 'Status'], true) && ! in_array($value, ['active', 'running', 'enabled', 'applied'], true);
            $propertyRows[] = TableRow::fromCells(TableCell::fromLine(Line::fromSpan(Span::styled($name, $dim))), $this->cell($value, $warn));
        }
        $propertiesTable = TableWidget::default()->widths(Constraint::length(18), Constraint::min(10))->rows(...$propertyRows);
        $propertiesTable->columnSpacing = 1;

        $properties = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Properties '))
            ->borderStyle($dim)
            ->widget($propertiesTable);

        $side = match ($pane) {
            'processes' => BlockWidget::default()
                ->borders(Borders::ALL)->borderType(BorderType::Rounded)
                ->titles(Title::fromString(' Recent logs '))
                ->borderStyle($dim)
                ->widget(ParagraphWidget::fromString(implode("\n", array_slice($this->logs, -max(1, $rows->get(1)->height - 2))))),
            default => BlockWidget::default()
                ->borders(Borders::ALL)->borderType(BorderType::Rounded)
                ->borderStyle($dim)
                ->widget(ParagraphWidget::fromString(' Nothing more to show for this record yet.')->style($dim)),
        };

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLines($crumbs)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::length(56), Constraint::min(30))
                    ->widgets($properties, $side),
            );
    }

    /** A small box over the screen listing the actions for the chosen row, at the pointer or in the middle. */
    private function menuPopup(Area $area): Widget
    {
        $menu = $this->menu ?? throw new RuntimeException('No menu is open.');
        $width = self::MENU_WIDTH;
        $lines = [];
        foreach (array_keys($menu['actions']) as $index => $label) {
            $text = str_pad('  '.$label, $width - 2);
            $lines[] = Line::fromSpan(Span::styled($text, $index === $menu['selected'] ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()));
        }
        $lines[] = Line::fromString(str_repeat(' ', $width - 2));
        $lines[] = Line::fromSpan(Span::styled(str_pad('  '.$menu['actions'][array_keys($menu['actions'])[$menu['selected']]], $width - 2), Style::default()->fg(AnsiColor::DarkGray)));
        $height = count($lines) + 2;

        [$left, $top] = $menu['at'] ?? [intdiv($area->width - $width, 2), intdiv($area->height - $height, 2)];
        $left = max(0, min($left, $area->width - $width));
        $top = max(0, min($top, $area->height - $height));
        $this->drawn['menu'] = ['area' => Area::fromScalars($left, $top, $width, $height), 'header' => false];

        $box = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD))
            ->titles(Title::fromString(" {$menu['title']} "))
            ->widget(ParagraphWidget::fromText(Text::fromLines(...$lines)));

        // A borderless block with padding places the box and leaves the rest of the screen as drawn.
        return BlockWidget::default()
            ->padding(Padding::fromScalars($left, max(0, $area->width - $width - $left), $top, max(0, $area->height - $height - $top)))
            ->widget($box);
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

    /**
     * A row that turns yellow when the record needs a look: yellow means "inspect".
     *
     * @param  list<string>  $cells
     */
    private function row(array $cells, string $status, bool $warn): TableRow
    {
        return TableRow::fromCells(...array_map(fn (string $cell): TableCell => $this->cell($cell, $warn), [...$cells, $status]));
    }

    private function cell(string $text, bool $warn): TableCell
    {
        $cell = TableCell::fromString($text);
        $cell->style = $warn ? Style::default()->fg(AnsiColor::Yellow) : Style::default();

        return $cell;
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

        foreach ($this->nodes as $node) {
            $gateway = in_array('gateway', $node['roles'], true);
            $this->metrics[$node['name']] = [
                'cores' => $gateway ? [0.08, 0.05, 0.11, 0.04] : [0.42, 0.37, 0.55, 0.28, 0.61, 0.33, 0.47, 0.39],
                'mem' => $gateway ? [1.4, 4] : [9.8, 16],
                'swap' => $gateway ? [0.0, 2] : [0.3, 4],
                'load' => $gateway ? [0.12, 0.10, 0.08] : [2.31, 1.98, 1.75],
                'uptime' => $gateway ? '41 days, 3:12' : '12 days, 17:40',
                'psi' => $gateway ? ['cpu' => 0.4, 'mem' => 0.0, 'io' => 0.9] : ['cpu' => 6.2, 'mem' => 0.3, 'io' => 12.8],
                'disks' => $gateway ? [['/', 9, 40]] : [['/', 31, 80], ['/srv/orbit', 188, 480]],
            ];
        }

        $this->logs = [
            date('H:i:s', time() - 9).'  Horizon started on charlie-shop/dev',
            date('H:i:s', time() - 6).'  Processed job App\\Jobs\\SyncOrders #4198 in 212 ms',
            date('H:i:s', time() - 3).'  Processed job App\\Jobs\\SendReceipt #4199 in 88 ms',
        ];
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
