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
 * Design sketch: Orbit as a sectioned screen on php-tui, refreshed on a tick.
 *
 * The sidebar lists the sections: Dashboard, then one per record family. A section is that
 * family's list; Enter or a click on a row opens the record's page, and pages stack so Esc
 * returns one step. Instances, Processes, and Schedules carry a node/app filter bar. The Nodes
 * list has a "+ create" link that opens a form following the node:add prompts. Every two
 * seconds the sketch "fetches" again and flips one Process status; node metrics random-walk
 * on their own tick. The data is the recorded fixtures plus made-up rows where no fixture
 * exists. Registered only when ORBIT_DESIGN=1.
 */
final class TopFlowCommand extends GatewayCommand
{
    private const array SECTIONS = [
        'dashboard' => 'Dashboard',
        'nodes' => 'Nodes',
        'apps' => 'Apps',
        'instances' => 'Instances',
        'processes' => 'Processes',
        'schedules' => 'Schedules',
        'databases' => 'Databases',
    ];

    private const int NAV_WIDTH = 16;

    private const int MENU_WIDTH = 36;

    private const int METRICS_TICK = 5;

    private const array CREATE_STEPS = ['Checking SSH access', 'Trusting the host key', 'Installing the Orbit agent', 'Joining the WireGuard network', 'Applying the node roles'];

    #[\Override]
    protected $signature = 'design:top
        {--tick=2 : Seconds between refreshes}';

    #[\Override]
    protected $description = 'Design sketch of a live sectioned screen; runs no Gateway request.';

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

    /**
     * Database connections as the Gateway records them, plus the users and tables the page would list.
     *
     * @var list<array<string, mixed>>
     */
    private array $databases = [];

    /** @var list<string> */
    private array $logs = [];

    /** @var list<string> */
    private array $instanceLogs = [];

    /** @var array<string, array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}> */
    private array $metrics = [];

    private float $lastMetrics = 0;

    private string $section = 'dashboard';

    /** The pane the arrows point at while hovering ('nav' or a page pane), and the pane Enter focused. */
    private string $hover = 'nav';

    private ?string $focus = null;

    /** @var array<string, int> */
    private array $selected = [];

    /**
     * Open record pages, last on top; empty means the section's own view.
     *
     * @var list<array{kind: string, row: array<string, mixed>}>
     */
    private array $pages = [];

    /** @var array{node: string|null, app: string|null} */
    private array $filters = ['node' => null, 'app' => null];

    /** @var array{kind: string, title: string, row: array<string, mixed>, actions: array<string, string>, selected: int, at: array{int, int}|null}|null */
    private ?array $menu = null;

    /**
     * The node create form: its fields, the active one, and how far the creation got.
     *
     * @var array{fields: list<array{string, string}>, active: int, stage: string, step: int, stepAt: float, fingerprint: string}|null
     */
    private ?array $form = null;

    private string $ran = '';

    /**
     * Where each pane, link, and button was drawn in the last frame, for mouse hit-testing.
     *
     * @var array<string, array{area: Area, header: bool}>
     */
    private array $drawn = [];

    /** @var list<string> */
    private array $paneOrder = [];

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
                    $this->instanceLogs[] = sprintf('[%s] local.INFO: GET /checkout 200 in %d ms', date('Y-m-d H:i:s'), 60 + ($refreshes * 53) % 400);
                }
                $this->advanceForm();

                while (($event = $terminal->events()->next()) !== null) {
                    if ($event instanceof CharKeyEvent) {
                        if ($event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL) {
                            break 2;
                        }
                        if ($this->form !== null) {
                            $this->typeInForm($event->char);
                        } elseif ($event->char === 'q') {
                            break 2;
                        } else {
                            $this->handleChar($event->char, $lastRefresh);
                        }
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

    private function handleChar(string $char, float &$lastRefresh): void
    {
        if ($this->menu !== null) {
            return;
        }
        $sections = array_keys(self::SECTIONS);
        match (true) {
            $char === 'r' => $lastRefresh = 0.0,
            $char === 'a' => $this->openMenu(),
            $char === 'c' && $this->section === 'nodes' && $this->pages === [] => $this->openForm(),
            $char === 'n' && $this->hasFilters() => $this->cycleFilter('node'),
            $char === 'p' && $this->hasFilters() => $this->cycleFilter('app'),
            ctype_digit($char) && isset($sections[(int) $char - 1]) => $this->goTo($sections[(int) $char - 1]),
            default => null,
        };
    }

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
        if ($this->form !== null) {
            $this->keyInForm($code);

            return;
        }

        if ($this->focus === null) {
            $this->hoverKey($code);

            return;
        }

        match ($code) {
            KeyCode::Down => $this->move(1),
            KeyCode::Up => $this->move(-1),
            KeyCode::Enter => $this->openSelected(),
            KeyCode::Esc => $this->focus = null,
            default => null,
        };
    }

    /** Hovering: the sidebar reacts straight away, the page panes wait for Enter. */
    private function hoverKey(KeyCode $code): void
    {
        if ($this->hover === 'nav') {
            $sections = array_keys(self::SECTIONS);
            $index = (int) array_search($this->section, $sections, true);
            match ($code) {
                KeyCode::Down => $this->goTo($sections[min(count($sections) - 1, $index + 1)]),
                KeyCode::Up => $this->goTo($sections[max(0, $index - 1)]),
                KeyCode::Right, KeyCode::Enter => $this->hover = $this->paneOrder[0] ?? 'nav',
                KeyCode::Esc => $this->back(),
                default => null,
            };

            return;
        }

        $index = (int) array_search($this->hover, $this->paneOrder, true);
        match ($code) {
            KeyCode::Left => $this->hover = 'nav',
            KeyCode::Down => $this->hover = $this->paneOrder[min(count($this->paneOrder) - 1, $index + 1)] ?? 'nav',
            KeyCode::Up => $this->hover = $this->paneOrder[max(0, $index - 1)] ?? 'nav',
            KeyCode::Enter => $this->focus = $this->hover,
            KeyCode::Esc => $this->back(),
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

        if ($this->form !== null) {
            if ($event->kind === MouseEventKind::Down && $event->button === MouseButton::Left) {
                if ($this->hitRow('back', $x, $y) !== null) {
                    $this->form = null;
                } elseif ($this->form['stage'] === 'edit') {
                    foreach (array_keys($this->form['fields']) as $index) {
                        if ($this->hitPoint("field:{$index}", $x, $y)) {
                            $this->form['active'] = $index;
                        }
                    }
                    if ($this->hitPoint('form:submit', $x, $y)) {
                        $this->submitForm();
                    }
                }
            }

            return;
        }

        if ($event->kind === MouseEventKind::ScrollDown || $event->kind === MouseEventKind::ScrollUp) {
            $pane = $this->paneAt($x, $y);
            if ($pane !== null && $pane !== 'nav') {
                $this->focus = $pane;
                $this->move($event->kind === MouseEventKind::ScrollDown ? 1 : -1);
            }

            return;
        }
        if ($event->kind !== MouseEventKind::Down) {
            return;
        }

        if ($event->button === MouseButton::Left) {
            if ($this->hitRow('back', $x, $y) !== null) {
                $this->back();

                return;
            }
            if ($this->hitPoint('create', $x, $y)) {
                $this->openForm();

                return;
            }
            foreach (['node', 'app'] as $filter) {
                if ($this->hitPoint("filter:{$filter}", $x, $y)) {
                    $this->cycleFilter($filter);

                    return;
                }
            }
            if (($link = $this->linkAt($x, $y)) !== null) {
                $this->follow($link);

                return;
            }
        }

        $pane = $this->paneAt($x, $y);
        if ($pane === null) {
            return;
        }
        if ($pane === 'nav') {
            $row = $this->hitRow('nav', $x, $y);
            $sections = array_keys(self::SECTIONS);
            if ($row !== null && isset($sections[$row])) {
                $this->goTo($sections[$row]);
            }

            return;
        }

        // A click lands on a pane and, when it hits a row, selects that row; a click on the
        // selected row opens it. A right click lists the row's actions.
        $this->hover = $pane;
        $this->focus = $pane;
        $rows = $this->rowsFor($pane);
        $row = $this->hitRow($pane, $x, $y);
        $hit = $row !== null && $row < count($rows);
        if ($event->button === MouseButton::Right) {
            if ($hit) {
                $this->selected[$pane] = $row;
            }
            $this->openMenu([$x, $y]);

            return;
        }
        if ($hit) {
            if (($this->selected[$pane] ?? 0) === $row) {
                $this->openSelected();
            } else {
                $this->selected[$pane] = $row;
            }
        }
    }

    private function paneAt(int $x, int $y): ?string
    {
        foreach ($this->drawn as $name => $drawn) {
            if (str_contains($name, ':') || $name === 'menu' || $name === 'back' || $name === 'create') {
                continue;
            }
            if ($this->inside($drawn['area'], $x, $y)) {
                return $name;
            }
        }

        return null;
    }

    private function inside(Area $area, int $x, int $y): bool
    {
        return $x >= $area->left() && $x < $area->right() && $y >= $area->top() && $y < $area->bottom();
    }

    private function hitPoint(string $name, int $x, int $y): bool
    {
        return isset($this->drawn[$name]) && $this->inside($this->drawn[$name]['area'], $x, $y);
    }

    /** The row index under a point inside a drawn pane: below its border and header, above its bottom border. */
    private function hitRow(string $name, int $x, int $y): ?int
    {
        $drawn = $this->drawn[$name] ?? null;
        if ($drawn === null || $x < $drawn['area']->left() || $x >= $drawn['area']->right()) {
            return null;
        }
        $first = $drawn['area']->top() + 1 + ($drawn['header'] ? 1 : 0);
        if ($y < $first || $y >= $drawn['area']->bottom() - 1) {
            return null;
        }

        return $y - $first;
    }

    private function linkAt(int $x, int $y): ?string
    {
        foreach ($this->drawn as $name => $drawn) {
            if (str_starts_with($name, 'link:') && $this->inside($drawn['area'], $x, $y)) {
                return $name;
            }
        }

        return null;
    }

    // ---- navigation ------------------------------------------------------------------------

    private function goTo(string $section): void
    {
        $this->section = $section;
        $this->pages = [];
        $this->form = null;
        $this->focus = null;
        $this->hover = 'nav';
        $this->filters = ['node' => null, 'app' => null];
    }

    private function move(int $step): void
    {
        if ($this->focus === null) {
            return;
        }
        $rows = count($this->rowsFor($this->focus));
        $this->selected[$this->focus] = max(0, min(max(0, $rows - 1), ($this->selected[$this->focus] ?? 0) + $step));
    }

    private function openSelected(): void
    {
        if ($this->focus === null) {
            return;
        }
        $rows = $this->rowsFor($this->focus);
        $row = $rows[$this->selected[$this->focus] ?? 0] ?? null;
        if ($row === null) {
            return;
        }
        if ($this->focus === 'attention') {
            $this->open($row['kind'], $row['record']);

            return;
        }
        if ($this->focus === 'targets') {
            $this->open('instances', $row);

            return;
        }
        if (in_array($this->focus, ['users', 'tables'], true)) {
            return;
        }
        $this->open($this->kindOf($this->focus), $row);
    }

    /** Which record family a pane's rows belong to. */
    private function kindOf(string $pane): string
    {
        return $pane === 'list' ? $this->section : $pane;
    }

    /** @param  array<string, mixed>  $row */
    private function open(string $kind, array $row): void
    {
        $this->pages[] = ['kind' => $kind, 'row' => $row];
        $this->focus = null;
        $this->hover = 'nav';
    }

    private function back(): void
    {
        if ($this->form !== null) {
            $this->form = null;

            return;
        }
        array_pop($this->pages);
        $this->focus = null;
    }

    /** @return array{kind: string, row: array<string, mixed>}|null */
    private function page(): ?array
    {
        return $this->pages[count($this->pages) - 1] ?? null;
    }

    /** A node or app link on a page opens that record's page. */
    private function follow(string $link): void
    {
        $row = $this->page()['row'] ?? [];
        if ($link === 'link:node') {
            $name = $row['node']['name'] ?? $row['node'] ?? null;
            foreach ($this->nodes as $node) {
                if ($node['name'] === $name) {
                    $this->open('nodes', $node);
                }
            }
        }
        if ($link === 'link:app') {
            foreach ($this->apps as $app) {
                if ($app['slug'] === ($row['app']['slug'] ?? null)) {
                    $this->open('apps', $app);
                }
            }
        }
    }

    private function hasFilters(): bool
    {
        return $this->pages === [] && in_array($this->section, ['instances', 'processes', 'schedules'], true);
    }

    /** The node or app filter steps through All and every value. */
    private function cycleFilter(string $filter): void
    {
        $values = $filter === 'node' ? array_column($this->nodes, 'name') : array_column($this->apps, 'slug');
        $current = $this->filters[$filter];
        $index = $current === null ? -1 : (int) array_search($current, $values, true);
        $this->filters[$filter] = $values[$index + 1] ?? null;
        $this->selected['list'] = 0;
    }

    // ---- actions ---------------------------------------------------------------------------

    /**
     * @param  array{int, int}|null  $at
     */
    private function openMenu(?array $at = null): void
    {
        $page = $this->page();
        $pane = $this->focus;
        if ($pane !== null && $pane !== 'nav') {
            $rows = $this->rowsFor($pane);
            $row = $rows[$this->selected[$pane] ?? 0] ?? null;
            $kind = $pane === 'attention' ? ($row['kind'] ?? '') : $this->kindOf($pane);
            $row = $pane === 'attention' ? ($row['record'] ?? null) : $row;
        } elseif ($page !== null) {
            $kind = $page['kind'];
            $row = $page['row'];
        } else {
            return;
        }
        if ($row === null) {
            return;
        }

        $actions = match ($kind) {
            'nodes' => ['show' => "node:show {$row['name']}", 'doctor' => "node:doctor {$row['name']}", 'ssh' => "node:ssh {$row['name']}"],
            'apps' => ['show' => "app:show {$row['slug']}", 'deploy' => "app:deploy {$row['slug']}"],
            'instances' => ['show' => "instance:show {$row['app']['slug']}/{$row['name']}", 'deploy' => "instance:deploy {$row['app']['slug']}/{$row['name']}", 'logs' => "instance:logs {$row['app']['slug']}/{$row['name']}", 'profile' => "instance:profile {$row['app']['slug']}/{$row['name']}"],
            'databases' => [
                'show' => "database:show {$row['slug']}",
                'tables' => "database:tables {$row['slug']}",
                'query' => "database:query {$row['slug']}",
                'create user' => "database:user:create {$row['slug']}",
                'destroy' => "database:destroy {$row['slug']}",
            ],
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
        if ($actions === []) {
            return;
        }

        $this->menu = ['kind' => $kind, 'title' => $this->rowTitle($kind, $row), 'row' => $row, 'actions' => $actions, 'selected' => 0, 'at' => $at];
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

        if ($this->menu['kind'] === 'processes') {
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
                    foreach ($this->pages as $i => $page) {
                        if ($page['kind'] === 'processes' && $page['row']['id'] === $row['id']) {
                            $this->pages[$i]['row'] = $this->processes[$index];
                        }
                    }
                }
            }
        }
        if ($this->menu['kind'] === 'schedules') {
            foreach ($this->schedules as $index => $schedule) {
                if ($schedule['id'] === $row['id'] && in_array($label, ['enable', 'disable'], true)) {
                    $this->schedules[$index]['status'] = $label === 'enable' ? 'enabled' : 'disabled';
                }
            }
        }

        $this->ran = "orbit {$command}";
        $this->menu = null;
    }

    // ---- node create form ------------------------------------------------------------------

    private function openForm(): void
    {
        $this->form = [
            'fields' => [['Name', ''], ['SSH host', ''], ['SSH port', '22'], ['User', 'root'], ['Roles', 'app-dev'], ['TLD', 'test']],
            'active' => 0,
            'stage' => 'edit',
            'step' => 0,
            'stepAt' => 0,
            'fingerprint' => 'SHA256:Qm3fL9xTz1a8YhVw2pR7dKcN4bE6sJ0uGiXo5mHt2Ac',
        ];
        $this->focus = null;
    }

    private function typeInForm(string $char): void
    {
        if ($this->form === null || $this->form['stage'] !== 'edit') {
            return;
        }
        $this->form['fields'][$this->form['active']][1] .= $char;
    }

    private function keyInForm(KeyCode $code): void
    {
        if ($this->form === null) {
            return;
        }
        $count = count($this->form['fields']);
        switch ($this->form['stage']) {
            case 'edit':
                match ($code) {
                    KeyCode::Backspace => $this->form['fields'][$this->form['active']][1] = mb_substr($this->form['fields'][$this->form['active']][1], 0, -1),
                    KeyCode::Tab, KeyCode::Down => $this->form['active'] = ($this->form['active'] + 1) % $count,
                    KeyCode::BackTab, KeyCode::Up => $this->form['active'] = ($this->form['active'] + $count - 1) % $count,
                    KeyCode::Enter => $this->form['active'] === $count - 1 ? $this->submitForm() : $this->form['active']++,
                    KeyCode::Esc => $this->form = null,
                    default => null,
                };
                break;
            case 'fingerprint':
                // The host key is confirmed by the user, as node:add asks; Esc aborts the whole add.
                match ($code) {
                    KeyCode::Enter => $this->form = [...$this->form, 'stage' => 'steps', 'step' => 1, 'stepAt' => microtime(true)],
                    KeyCode::Esc => $this->form = null,
                    default => null,
                };
                break;
            case 'done':
                if ($code === KeyCode::Enter || $code === KeyCode::Esc) {
                    $this->finishForm();
                }
                break;
        }
    }

    /** Every required field needs a value before the add starts; the first empty one gets the cursor. */
    private function submitForm(): void
    {
        if ($this->form === null) {
            return;
        }
        foreach ($this->form['fields'] as $index => [$label, $value]) {
            if (trim($value) === '') {
                $this->form['active'] = $index;

                return;
            }
        }
        $this->form['stage'] = 'steps';
        $this->form['step'] = 0;
        $this->form['stepAt'] = microtime(true);
    }

    /** Steps advance on their own; the second one stops to ask about the host key. */
    private function advanceForm(): void
    {
        if ($this->form === null || $this->form['stage'] !== 'steps' || microtime(true) - $this->form['stepAt'] < 0.9) {
            return;
        }
        $next = $this->form['step'] + 1;
        if ($next === 1) {
            $this->form['stage'] = 'fingerprint';

            return;
        }
        if ($next >= count(self::CREATE_STEPS)) {
            $this->form['stage'] = 'done';
            $this->form['step'] = count(self::CREATE_STEPS);

            return;
        }
        $this->form['step'] = $next;
        $this->form['stepAt'] = microtime(true);
    }

    /** The created node joins the list and its page opens. */
    private function finishForm(): void
    {
        if ($this->form === null) {
            return;
        }
        $values = array_column($this->form['fields'], 1, 0);
        $node = [
            'id' => count($this->nodes) + 1,
            'name' => $values['Name'],
            'status' => 'active',
            'roles' => array_map('trim', explode(',', $values['Roles'])),
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => $values['TLD'],
            'public_ssh_host' => $values['SSH host'],
            'public_ssh_port' => (int) $values['SSH port'],
            'user' => $values['User'],
            'wireguard_ip' => '10.44.0.'.(10 + count($this->nodes)),
            'lan_ip' => null,
        ];
        $this->nodes[] = $node;
        $this->metrics[$node['name']] = ['cores' => [0.03, 0.02, 0.04, 0.02], 'mem' => [0.9, 8], 'swap' => [0.0, 2], 'uptime' => '0 days, 0:01', 'disks' => [['/', 6, 80]]];
        $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '22/tcp', 'action' => 'allow', 'source' => '10.44.0.0/16', 'status' => 'applied'];
        $this->ran = "orbit node:add {$node['name']} --host {$values['SSH host']}";
        $this->form = null;
        $this->open('nodes', $node);
    }

    // ---- data ------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function rowsFor(string $pane): array
    {
        $page = $this->page();

        return match ($pane) {
            'nav' => array_map(fn (string $title): array => ['title' => $title], array_values(self::SECTIONS)),
            'list' => $this->listRows(),
            'attention' => $this->attentionRows(),
            'instances' => match ($page['kind'] ?? '') {
                'nodes' => array_values(array_filter($this->instances, fn (array $i): bool => $i['node']['name'] === $page['row']['name'])),
                'apps' => array_values(array_filter($this->instances, fn (array $i): bool => $i['app']['slug'] === $page['row']['slug'])),
                default => [],
            },
            'processes' => match ($page['kind'] ?? '') {
                'instances' => array_values(array_filter($this->processes, fn (array $p): bool => $p['target_type'] === 'app_instance' && $p['target_id'] === $page['row']['id'])),
                'nodes' => array_values(array_filter($this->processes, fn (array $p): bool => $p['target_type'] === 'node' && $p['node'] === $page['row']['name'])),
                default => [],
            },
            'schedules' => match ($page['kind'] ?? '') {
                'instances' => array_values(array_filter($this->schedules, fn (array $s): bool => $s['instance_id'] === $page['row']['id'])),
                'apps' => array_values(array_filter($this->schedules, fn (array $s): bool => str_starts_with($this->instanceName($s['instance_id']), $page['row']['slug'].'/'))),
                default => [],
            },
            'firewall' => array_values(array_filter($this->firewall, fn (array $f): bool => $f['node'] === ($page['row']['name'] ?? null))),
            'targets' => ($page['kind'] ?? '') === 'databases' ? array_values(array_filter($this->instances, fn (array $i): bool => in_array($i['id'], $page['row']['targets'], true))) : [],
            'users' => ($page['kind'] ?? '') === 'databases' ? $page['row']['users'] : [],
            'tables' => ($page['kind'] ?? '') === 'databases' ? $page['row']['tables'] : [],
            default => [],
        };
    }

    /**
     * The section's list, narrowed by the node and app filters where the section has them.
     *
     * @return list<array<string, mixed>>
     */
    private function listRows(): array
    {
        $node = $this->filters['node'];
        $app = $this->filters['app'];
        $instanceIds = array_column(array_filter($this->instances, fn (array $i): bool => ($node === null || $i['node']['name'] === $node) && ($app === null || $i['app']['slug'] === $app)), 'id');

        return match ($this->section) {
            'nodes' => $this->nodes,
            'apps' => $this->apps,
            'instances' => array_values(array_filter($this->instances, fn (array $i): bool => in_array($i['id'], $instanceIds, true))),
            'processes' => array_values(array_filter($this->processes, fn (array $p): bool => $p['target_type'] === 'node'
                ? $app === null && ($node === null || $p['node'] === $node)
                : in_array($p['target_id'], $instanceIds, true))),
            'schedules' => array_values(array_filter($this->schedules, fn (array $s): bool => in_array($s['instance_id'], $instanceIds, true))),
            'databases' => array_values(array_filter($this->databases, fn (array $d): bool => $node === null || $d['node'] === $node)),
            default => [],
        };
    }

    /**
     * Everything that is yellow somewhere, gathered for the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    private function attentionRows(): array
    {
        $rows = [];
        foreach ($this->nodes as $node) {
            if ($node['status'] !== 'active') {
                $rows[] = ['kind' => 'nodes', 'record' => $node, 'label' => 'Node', 'name' => $node['name'], 'where' => '—', 'state' => $node['status']];
            }
        }
        foreach ($this->instances as $instance) {
            if ($instance['status'] !== 'active') {
                $rows[] = ['kind' => 'instances', 'record' => $instance, 'label' => 'Instance', 'name' => "{$instance['app']['slug']}/{$instance['name']}", 'where' => $instance['node']['name'], 'state' => $instance['status']];
            }
        }
        foreach ($this->processes as $process) {
            if ($process['runtime_status'] !== $process['desired_state']) {
                $rows[] = ['kind' => 'processes', 'record' => $process, 'label' => 'Process', 'name' => $process['name'], 'where' => $this->processOwner($process), 'state' => "{$process['runtime_status']}, wanted {$process['desired_state']}"];
            }
        }
        foreach ($this->schedules as $schedule) {
            if ($schedule['status'] !== 'enabled') {
                $rows[] = ['kind' => 'schedules', 'record' => $schedule, 'label' => 'Schedule', 'name' => $schedule['name'], 'where' => $this->instanceName($schedule['instance_id']), 'state' => $schedule['status']];
            }
        }
        foreach ($this->firewall as $rule) {
            if ($rule['status'] !== 'applied') {
                $rows[] = ['kind' => 'firewall', 'record' => $rule, 'label' => 'Firewall', 'name' => "{$rule['port']} {$rule['action']} {$rule['source']}", 'where' => $rule['node'], 'state' => $rule['status']];
            }
        }

        return $rows;
    }

    private function instanceName(int $id): string
    {
        foreach ($this->instances as $instance) {
            if ($instance['id'] === $id) {
                return "{$instance['app']['slug']}/{$instance['name']}";
            }
        }

        return '—';
    }

    private function instanceNode(int $id): string
    {
        foreach ($this->instances as $instance) {
            if ($instance['id'] === $id) {
                return $instance['node']['name'];
            }
        }

        return '—';
    }

    /** @param  array<string, mixed>  $process */
    private function processOwner(array $process): string
    {
        return $process['target_type'] === 'node' ? "node {$process['node']}" : $this->instanceName($process['target_id']);
    }

    /** @param  array<string, mixed>  $process */
    private function processNode(array $process): string
    {
        return $process['target_type'] === 'node' ? $process['node'] : $this->instanceNode($process['target_id']);
    }

    /** @param  array<string, mixed>  $row */
    private function rowTitle(string $kind, array $row): string
    {
        return match ($kind) {
            'nodes' => $row['name'],
            'apps' => $row['slug'],
            'instances' => "{$row['app']['slug']}/{$row['name']}",
            'processes', 'schedules' => $row['name'],
            'databases' => $row['slug'],
            'firewall' => "{$row['port']} {$row['action']} {$row['source']}",
            default => '',
        };
    }

    /**
     * The properties a page lists, named as the show commands name them.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function properties(string $kind, array $row): array
    {
        $value = fn (mixed $v): string => match (true) {
            $v === null, $v === '' => '—',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => implode(', ', $v),
            default => (string) $v,
        };
        $properties = match ($kind) {
            'nodes' => ['Name' => $row['name'], 'Status' => $row['status'], 'Roles' => $row['roles'], 'Platform' => $row['platform'] ?? null, 'Architecture' => $row['architecture'] ?? null, 'TLD' => $row['tld'] ?? null, 'WireGuard IP' => $row['wireguard_ip'] ?? null, 'SSH' => isset($row['public_ssh_host']) ? "{$row['user']}@{$row['public_ssh_host']}:{$row['public_ssh_port']}" : null],
            'apps' => ['Name' => $row['name'], 'Slug' => $row['slug'], 'Repository' => $row['repository_url'] ?? null, 'Default branch' => $row['default_branch'] ?? null, 'Root' => $row['root'] ?? null],
            'instances' => ['Name' => $row['name'], 'App' => $row['app']['slug'], 'Node' => $row['node']['name'], 'Environment' => $row['environment'], 'Domain' => $row['domain'], 'Status' => $row['status'], 'Checkout' => $row['checkout_path'] ?? null, 'Selected branch' => $row['selected_branch'] ?? null],
            'databases' => ['Slug' => $row['slug'], 'Driver' => $row['driver'], 'Node' => $row['node'], 'Host' => isset($row['host']) ? "{$row['host']}:{$row['port']}" : null, 'Path' => $row['path'] ?? null, 'Database' => $row['database'] ?? null, 'Username' => $row['username'] ?? null, 'Password' => isset($row['username']) ? '••••••••' : null, 'Server process' => $row['process'] ?? null],
            'processes' => ['Name' => $row['name'], ...isset($row['engine']) ? ['Engine' => $row['engine']] : [], 'Owner' => $this->processOwner($row), 'Node' => $this->processNode($row), 'Runtime' => $row['runtime'], 'Working directory' => $row['working_directory'] ?? null, 'Restart policy' => $row['restart_policy'] ?? null, 'Desired state' => $row['desired_state'], 'Runtime status' => $row['runtime_status']],
            'schedules' => ['Name' => $row['name'], 'Instance' => $this->instanceName($row['instance_id']), 'Node' => $this->instanceNode($row['instance_id']), 'Command' => $row['command'], 'Expression' => $row['expression'], 'Next run' => $row['next_run'], 'Status' => $row['status']],
            'firewall' => ['Port' => $row['port'], 'Action' => $row['action'], 'Source' => $row['source'], 'Status' => $row['status'], 'Node' => $row['node']],
            default => [],
        };

        return array_map($value, $properties);
    }

    // ---- screens ---------------------------------------------------------------------------

    private function screen(int $refreshes, float $lastRefresh, float $tick, Area $area): Widget
    {
        $this->drawn = [];
        $this->paneOrder = [];
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $age = max(0, (int) round(microtime(true) - $lastRefresh));

        $rows = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(10), Constraint::length(1)])->split($area);
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::length(self::NAV_WIDTH), Constraint::min(40)])->split($rows->get(1));
        $this->drawn['nav'] = ['area' => $columns->get(0), 'header' => false];

        $header = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::min(12), Constraint::length(60))
            ->widgets(
                ParagraphWidget::fromString('  orbit')->style(Style::default()->addModifier(Modifier::BOLD)),
                ParagraphWidget::fromString("Gateway 10.44.0.1 · refreshed {$age}s ago · every {$tick}s · {$refreshes} refreshes  ")->style($dim)->alignment(HorizontalAlignment::Right),
            );

        $body = $this->form !== null ? $this->formPage($columns->get(1)) : ($this->page() === null ? $this->sectionView($columns->get(1)) : $this->recordPage($columns->get(1)));

        $footer = ParagraphWidget::fromString(match (true) {
            $this->menu !== null => '  ↑↓ choose · Enter or click runs · Esc closes',
            $this->form !== null => match ($this->form['stage']) {
                'edit' => '  type to fill · Tab or ↑↓ next field · Enter on the last field creates · Esc cancels',
                'fingerprint' => '  Enter trusts the host key · Esc aborts',
                'done' => '  Enter opens the node',
                default => '  adding the node…',
            },
            $this->page() !== null && $this->focus === null => '  ←→ sidebar or page · ↑↓ panes · Enter focuses · Esc or ‹ back · a or right-click actions · q leave',
            $this->focus === null => '  ↑↓ sections · → into the page · 1-7 jump · '.($this->section === 'nodes' ? 'c or + create · ' : '').($this->hasFilters() ? 'n/p filters · ' : '').'q leave',
            default => '  ↑↓ move · Enter or click again opens · a or right-click actions · Esc back to panes · q leave',
        }.($this->ran !== '' ? "  │  Ran {$this->ran}" : ''))->style($dim);

        $screen = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(10), Constraint::length(1))
            ->widgets(
                $header,
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::length(self::NAV_WIDTH), Constraint::min(40))
                    ->widgets($this->nav(), $body),
                $footer,
            );

        return $this->menu === null ? $screen : CompositeWidget::fromWidgets($screen, $this->menuPopup($area));
    }

    private function nav(): Widget
    {
        $hovered = $this->hover === 'nav' && $this->focus === null && $this->form === null;
        $rows = [];
        foreach (self::SECTIONS as $key => $title) {
            $rows[] = TableRow::fromStrings($title);
        }
        $table = TableWidget::default()
            ->widths(Constraint::percentage(96))
            ->rows(...$rows)
            ->select((int) array_search($this->section, array_keys(self::SECTIONS), true))
            ->highlightSymbol('› ')
            ->highlightStyle($hovered ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()->addModifier(Modifier::BOLD));

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle($hovered ? Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD) : Style::default()->fg(AnsiColor::DarkGray))
            ->widget($table);
    }

    /** A section without an open page: the dashboard, or the family's list with its title row. */
    private function sectionView(Area $area): Widget
    {
        if ($this->section === 'dashboard') {
            return $this->dashboard($area);
        }

        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(5)])->split($area);
        $this->drawn['list'] = ['area' => $split->get(1), 'header' => true];
        $this->paneOrder = ['list'];

        $spans = [Span::styled('  '.self::SECTIONS[$this->section], Style::default()->addModifier(Modifier::BOLD))];
        $x = $split->get(0)->left() + 2 + strlen(self::SECTIONS[$this->section]);
        if ($this->section === 'nodes') {
            $spans[] = Span::fromString('   ');
            $spans[] = Span::styled('+ create', Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::UNDERLINED));
            $this->drawn['create'] = ['area' => Area::fromScalars($x + 3, $split->get(0)->top(), 8, 1), 'header' => false];
        }
        if ($this->hasFilters()) {
            foreach (['node', 'app'] as $filter) {
                $text = "{$filter}: ".($this->filters[$filter] ?? 'all').' ▾';
                $spans[] = Span::fromString('   ');
                $spans[] = Span::styled($text, $this->filters[$filter] === null ? $dim : Style::default()->fg(AnsiColor::Cyan));
                $x += 3;
                $this->drawn["filter:{$filter}"] = ['area' => Area::fromScalars($x, $split->get(0)->top(), mb_strlen($text), 1), 'header' => false];
                $x += mb_strlen($text);
            }
        }

        [$headers, $widths, $rows] = $this->listTable();

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(...$spans))),
                $this->pane('list', '', $headers, $widths, $rows),
            );
    }

    /**
     * The columns and rows of the current section's list.
     *
     * @return array{list<string>, list<Constraint>, list<TableRow>}
     */
    private function listTable(): array
    {
        $rows = $this->listRows();

        return match ($this->section) {
            'nodes' => [
                ['Name', 'Status', 'Roles', 'WireGuard IP', 'Instances', 'Uptime'],
                [Constraint::percentage(18), Constraint::percentage(12), Constraint::percentage(20), Constraint::percentage(16), Constraint::percentage(12), Constraint::percentage(18)],
                array_map(fn (array $n): TableRow => $this->row([$n['name'], $n['status'], implode(', ', $n['roles']), $n['wireguard_ip'] ?? '—', (string) count(array_filter($this->instances, fn (array $i): bool => $i['node']['name'] === $n['name']))], $this->metrics[$n['name']]['uptime'] ?? '—', $n['status'] !== 'active'), $rows),
            ],
            'apps' => [
                ['Slug', 'Name', 'Default branch', 'Instances', 'Nodes'],
                [Constraint::percentage(22), Constraint::percentage(30), Constraint::percentage(18), Constraint::percentage(12), Constraint::percentage(14)],
                array_map(function (array $a): TableRow {
                    $instances = array_filter($this->instances, fn (array $i): bool => $i['app']['slug'] === $a['slug']);

                    return $this->row([$a['slug'], $a['name'], $a['default_branch'] ?? 'main', (string) count($instances)], (string) count(array_unique(array_column(array_column($instances, 'node'), 'name'))), false);
                }, $rows),
            ],
            'instances' => [
                ['App', 'Name', 'Environment', 'Node', 'Domain', 'Status'],
                [Constraint::percentage(18), Constraint::percentage(14), Constraint::percentage(14), Constraint::percentage(12), Constraint::percentage(28), Constraint::percentage(10)],
                array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['node']['name'], $i['domain']], $i['status'], $i['status'] !== 'active'), $rows),
            ],
            'processes' => [
                ['Name', 'Owner', 'Node', 'Runtime', 'Status'],
                [Constraint::percentage(20), Constraint::percentage(30), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(16)],
                array_map(fn (array $p): TableRow => $this->row([$p['name'], $this->processOwner($p), $this->processNode($p), $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $rows),
            ],
            'schedules' => [
                ['Name', 'Instance', 'Node', 'Command', 'Next run'],
                [Constraint::percentage(18), Constraint::percentage(20), Constraint::percentage(12), Constraint::percentage(32), Constraint::percentage(14)],
                array_map(fn (array $s): TableRow => $this->row([$s['name'], $this->instanceName($s['instance_id']), $this->instanceNode($s['instance_id']), $s['command']], $s['next_run'], $s['status'] !== 'enabled'), $rows),
            ],
            'databases' => [
                ['Slug', 'Driver', 'Node', 'Host', 'Database', 'Instances', 'Users'],
                [Constraint::percentage(16), Constraint::percentage(10), Constraint::percentage(12), Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(10), Constraint::percentage(10)],
                array_map(fn (array $d): TableRow => $this->row([$d['slug'], $d['driver'], $d['node'] ?? '—', $d['host'] ?? $d['path'], $d['database'] ?? '—', (string) count($d['targets'])], (string) count($d['users']), false), $rows),
            ],
            default => [[], [], []],
        };
    }

    /** The dashboard: counts, one compact metrics line per node, and everything that needs a look. */
    private function dashboard(Area $area): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $nodeBlocks = count($this->nodes) * 3;
        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(3), Constraint::length($nodeBlocks), Constraint::min(5)])->split($area);
        $this->drawn['attention'] = ['area' => $split->get(2), 'header' => true];
        $this->paneOrder = ['attention'];

        $nodeWidgets = [];
        foreach ($this->nodes as $node) {
            $m = $this->metrics[$node['name']];
            $inner = $area->width - 4;
            $third = intdiv($inner - 4, 3);
            $cpu = array_sum($m['cores']) / count($m['cores']);
            [$mount, $used, $total] = $m['disks'][0];
            $nodeWidgets[] = BlockWidget::default()
                ->borders(Borders::ALL)->borderType(BorderType::Rounded)
                ->titles(Title::fromString(" {$node['name']} · {$node['status']} · up {$m['uptime']} "))
                ->borderStyle($node['status'] === 'active' ? $dim : Style::default()->fg(AnsiColor::Yellow))
                ->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(...[
                    ...$this->bar('cpu', $cpu, sprintf('%3.0f%%', $cpu * 100), $third),
                    Span::fromString('  '),
                    ...$this->bar('mem', $m['mem'][0] / $m['mem'][1], sprintf('%.1fG/%.0fG', $m['mem'][0], $m['mem'][1]), $third),
                    Span::fromString('  '),
                    ...$this->bar(str_pad($mount, 3), $used / $total, sprintf('%.0fG/%.0fG', $used, $total), $inner - 2 * $third - 4, [80, 90]),
                ]))));
        }

        $attention = array_map(fn (array $a): TableRow => $this->row([$a['label'], $a['name'], $a['where']], $a['state'], true), $this->attentionRows());

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(3), Constraint::length($nodeBlocks), Constraint::min(5))
            ->widgets(
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)
                    ->widget(ParagraphWidget::fromText(Text::fromLines($this->stats($area->width - 2)))),
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(...array_fill(0, count($nodeWidgets), Constraint::length(3)))
                    ->widgets(...$nodeWidgets),
                $this->pane('attention', ' Needs attention ', ['Kind', 'Name', 'Where', 'State'], [Constraint::percentage(12), Constraint::percentage(32), Constraint::percentage(26), Constraint::percentage(28)], $attention, 'Nothing needs attention.'),
            );
    }

    /** Counts for the whole network, a count in yellow when something in it needs a look. */
    private function stats(int $width): Line
    {
        $off = fn (array $rows, callable $ok): int => count(array_filter($rows, fn (array $row): bool => ! $ok($row)));
        $segments = [
            ['Nodes', count($this->nodes), $off($this->nodes, fn (array $n): bool => $n['status'] === 'active')],
            ['Apps', count($this->apps), 0],
            ['Instances', count($this->instances), $off($this->instances, fn (array $i): bool => $i['status'] === 'active')],
            ['Processes', count($this->processes), $off($this->processes, fn (array $p): bool => $p['runtime_status'] === $p['desired_state'])],
            ['Schedules', count($this->schedules), $off($this->schedules, fn (array $s): bool => $s['status'] === 'enabled')],
            ['Firewall', count($this->firewall), $off($this->firewall, fn (array $f): bool => $f['status'] === 'applied')],
        ];
        $texts = array_map(fn (array $segment): string => "{$segment[0]} {$segment[1]}", $segments);
        $slack = max(0, $width - 2 - array_sum(array_map('strlen', $texts)));
        $gaps = max(1, count($texts) - 1);
        $spans = [Span::fromString(' ')];
        foreach ($segments as $index => [$label, $count, $warn]) {
            if ($index > 0) {
                $spans[] = Span::fromString(str_repeat(' ', intdiv($slack * $index, $gaps) - intdiv($slack * ($index - 1), $gaps)));
            }
            $spans[] = Span::styled($texts[$index], $warn > 0 ? Style::default()->fg(AnsiColor::Yellow) : Style::default());
        }

        return Line::fromSpans(...$spans);
    }

    /** One record: crumbs, properties, and the panes the family has. */
    private function recordPage(Area $area): Widget
    {
        $page = $this->page() ?? throw new RuntimeException('No page is open.');
        $kind = $page['kind'];
        $row = $page['row'];
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        $split = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length(1), Constraint::min(5)])->split($area);
        $body = $split->get(1);
        $this->drawn['back'] = ['area' => Area::fromScalars($split->get(0)->left(), $split->get(0)->top(), 10, 1), 'header' => false];

        $label = match ($kind) {
            'nodes' => 'Node',
            'apps' => 'App',
            'instances' => 'App instance',
            'processes' => 'Process',
            'schedules' => 'Schedule',
            'databases' => 'Database',
            'firewall' => 'Firewall rule',
            default => '',
        };
        $crumbs = ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(
            Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)),
            Span::styled("{$label}: ", $dim),
            Span::styled($this->rowTitle($kind, $row), Style::default()->addModifier(Modifier::BOLD)),
        )));

        // Properties first; App and Node values are links to those records' pages.
        $propertiesWidth = in_array($kind, ['nodes', 'instances', 'databases'], true) ? intdiv($body->width * 40, 100) : $body->width;
        $propertyRows = [];
        $index = 0;
        foreach ($this->properties($kind, $row) as $name => $value) {
            $warn = in_array($name, ['Runtime status', 'Status'], true) && ! in_array($value, ['active', 'running', 'enabled', 'applied'], true);
            $link = in_array($name, ['App', 'Node'], true) && $value !== '—';
            if ($link) {
                $this->drawn['link:'.strtolower($name)] = ['area' => Area::fromScalars($body->left() + 1, $body->top() + 1 + $index, max(10, $propertiesWidth - 2), 1), 'header' => false];
            }
            $valueCell = $link ? TableCell::fromLine(Line::fromSpan(Span::styled($value, Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::UNDERLINED)))) : $this->cell($value, $warn);
            $propertyRows[] = TableRow::fromCells(TableCell::fromLine(Line::fromSpan(Span::styled($name, $dim))), $valueCell);
            $index++;
        }
        $table = TableWidget::default()->widths(Constraint::length(18), Constraint::min(10))->rows(...$propertyRows);
        $table->columnSpacing = 1;
        $properties = BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Properties '))->borderStyle($dim)->widget($table);
        $propertiesHeight = count($propertyRows) + 2;

        $content = match ($kind) {
            'nodes' => $this->nodePage($row, $properties, $propertiesHeight, $body),
            'apps' => $this->appPage($properties, $propertiesHeight, $body),
            'instances' => $this->instancePage($properties, $propertiesHeight, $body),
            'databases' => $this->databasePage($properties, $propertiesHeight, $body),
            'schedules' => $this->stackedPage($properties, $propertiesHeight, ' Runs ', $this->scheduleRuns($row), $body),
            default => $this->stackedPage($properties, $propertiesHeight, ' Logs ', $this->logs, $body),
        };

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets($crumbs, $content);
    }

    /**
     * Properties beside the metrics block, then the node's instances, then its own processes beside its firewall.
     *
     * @param  array<string, mixed>  $node
     */
    private function nodePage(array $node, Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $metricsHeight = $this->metricsHeight($node['name']);
        $top = max($propertiesHeight, $metricsHeight);
        $instances = $this->rowsFor('instances');
        $constraints = [Constraint::length($top), Constraint::length(min(count($instances) + 3, max(5, $body->height - $top - 8))), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $topColumns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(60)])->split($rows->get(0));
        $bottom = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(50), Constraint::percentage(50)])->split($rows->get(2));
        $this->drawn['instances'] = ['area' => $rows->get(1), 'header' => true];
        $this->drawn['processes'] = ['area' => $bottom->get(0), 'header' => true];
        $this->drawn['firewall'] = ['area' => $bottom->get(1), 'header' => true];
        $this->paneOrder = ['instances', 'processes', 'firewall'];

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                    ->widgets($properties, $this->metricsPanel($node['name'], $topColumns->get(1)->width)),
                $this->pane('instances', ' Instances on this node ', ['App', 'Name', 'Environment', 'Domain', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(32), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['environment'], $i['domain']], $i['status'], $i['status'] !== 'active'), $instances)),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(50), Constraint::percentage(50))
                    ->widgets(
                        $this->pane('processes', ' Node processes ', ['Name', 'Runtime', 'Status'], [Constraint::percentage(46), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $this->rowsFor('processes'))),
                        $this->pane('firewall', ' Firewall ', ['Port', 'Action', 'Source', 'Status'], [Constraint::percentage(22), Constraint::percentage(16), Constraint::percentage(40), Constraint::percentage(18)], array_map(fn (array $f): TableRow => $this->row([$f['port'], $f['action'], $f['source']], $f['status'], $f['status'] !== 'applied'), $this->rowsFor('firewall'))),
                    ),
            );
    }

    /** Properties, then the app's instances, then their schedules. */
    private function appPage(Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $instances = $this->rowsFor('instances');
        $constraints = [Constraint::length($propertiesHeight), Constraint::length(count($instances) + 3), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $this->drawn['instances'] = ['area' => $rows->get(1), 'header' => true];
        $this->drawn['schedules'] = ['area' => $rows->get(2), 'header' => true];
        $this->paneOrder = ['instances', 'schedules'];

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                $properties,
                $this->pane('instances', ' Instances ', ['Name', 'Environment', 'Node', 'Domain', 'Status'], [Constraint::percentage(16), Constraint::percentage(16), Constraint::percentage(14), Constraint::percentage(40), Constraint::percentage(12)], array_map(fn (array $i): TableRow => $this->row([$i['name'], $i['environment'], $i['node']['name'], $i['domain']], $i['status'], $i['status'] !== 'active'), $instances)),
                $this->pane('schedules', ' Schedules ', ['Name', 'Instance', 'Command', 'Next run'], [Constraint::percentage(20), Constraint::percentage(22), Constraint::percentage(36), Constraint::percentage(20)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $this->instanceName($s['instance_id']), $s['command']], $s['next_run'], $s['status'] !== 'enabled'), $this->rowsFor('schedules'))),
            );
    }

    /** Properties, Processes, and Schedules side by side over the full width, the logs below. */
    private function instancePage(Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $processes = $this->rowsFor('processes');
        $schedules = $this->rowsFor('schedules');
        $topHeight = max($propertiesHeight, count($processes) + 3, count($schedules) + 3);
        $rows = Layout::default()->direction(Direction::Vertical)->constraints([Constraint::length($topHeight), Constraint::min(4)])->split($body);
        $columns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(26), Constraint::percentage(34)])->split($rows->get(0));
        $this->drawn['processes'] = ['area' => $columns->get(1), 'header' => true];
        $this->drawn['schedules'] = ['area' => $columns->get(2), 'header' => true];
        $this->paneOrder = ['processes', 'schedules'];

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($topHeight), Constraint::min(4))
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(26), Constraint::percentage(34))
                    ->widgets(
                        $properties,
                        $this->pane('processes', ' Processes ', ['Name', 'Runtime', 'Status'], [Constraint::percentage(46), Constraint::percentage(26), Constraint::percentage(24)], array_map(fn (array $p): TableRow => $this->row([$p['name'], $p['runtime']], $p['runtime_status'], $p['runtime_status'] !== $p['desired_state']), $processes)),
                        $this->pane('schedules', ' Schedules ', ['Name', 'Command', 'Next run'], [Constraint::percentage(30), Constraint::percentage(42), Constraint::percentage(24)], array_map(fn (array $s): TableRow => $this->row([$s['name'], $s['command']], $s['next_run'], $s['status'] !== 'enabled'), $schedules)),
                    ),
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Logs '))->borderStyle($dim)
                    ->widget(ParagraphWidget::fromString(implode("\n", array_slice($this->instanceLogs, -max(1, $rows->get(1)->height - 2))))),
            );
    }

    /** Properties beside the attached instances, then the database's users beside its tables. */
    private function databasePage(Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $targets = $this->rowsFor('targets');
        $users = $this->rowsFor('users');
        $tables = $this->rowsFor('tables');
        $top = max($propertiesHeight, count($targets) + 3);
        $constraints = [Constraint::length($top), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $topColumns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(60)])->split($rows->get(0));
        $bottom = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(55), Constraint::percentage(45)])->split($rows->get(1));
        $this->drawn['targets'] = ['area' => $topColumns->get(1), 'header' => true];
        $this->drawn['users'] = ['area' => $bottom->get(0), 'header' => true];
        $this->drawn['tables'] = ['area' => $bottom->get(1), 'header' => true];
        $this->paneOrder = ['targets', 'users', 'tables'];

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                    ->widgets(
                        $properties,
                        $this->pane('targets', ' Attached instances ', ['App', 'Name', 'Node', 'Prefix', 'Status'], [Constraint::percentage(24), Constraint::percentage(20), Constraint::percentage(18), Constraint::percentage(20), Constraint::percentage(14)], array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['node']['name'], $i['name'] === 'dev' ? '' : "{$i['name']}_"], $i['status'], $i['status'] !== 'active'), $targets)),
                    ),
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(55), Constraint::percentage(45))
                    ->widgets(
                        $this->pane('users', ' Users ', ['Username', 'Privileges', 'Used by', 'Created'], [Constraint::percentage(26), Constraint::percentage(24), Constraint::percentage(28), Constraint::percentage(20)], array_map(fn (array $u): TableRow => $this->row([$u['username'], $u['privileges'], $u['used_by']], $u['created'], $u['used_by'] === '—'), $users), 'No users recorded. database:user:create records the next one.'),
                        $this->pane('tables', ' Tables ', ['Table', 'Rows', 'Size'], [Constraint::percentage(50), Constraint::percentage(24), Constraint::percentage(24)], array_map(fn (array $t): TableRow => $this->row([$t['name'], $t['rows']], $t['size'], false), $tables)),
                    ),
            );
    }

    /**
     * Properties across the top, then lines the record produced (logs, runs) over the full width.
     *
     * @param  list<string>  $lines
     */
    private function stackedPage(Widget $properties, int $propertiesHeight, string $title, array $lines, Area $body): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $tail = max(1, $body->height - $propertiesHeight - 2);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length($propertiesHeight), Constraint::min(4))
            ->widgets(
                $properties,
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString($title))->borderStyle($dim)
                    ->widget(ParagraphWidget::fromString(implode("\n", array_slice($lines, -$tail)))),
            );
    }

    /** The node create form, then its steps, then the result. */
    private function formPage(Area $area): Widget
    {
        $form = $this->form ?? throw new RuntimeException('No form is open.');
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $this->drawn['back'] = ['area' => Area::fromScalars($area->left(), $area->top(), 10, 1), 'header' => false];

        $lines = [Line::fromString('')];
        foreach ($form['fields'] as $index => [$label, $value]) {
            $active = $form['stage'] === 'edit' && $index === $form['active'];
            $this->drawn["field:{$index}"] = ['area' => Area::fromScalars($area->left() + 1, $area->top() + 2 + count($lines), $area->width - 2, 1), 'header' => false];
            $lines[] = Line::fromSpans(
                Span::styled('  '.str_pad($label, 12), $dim),
                Span::styled($active ? "{$value}▏" : ($value === '' ? '—' : $value), $active ? Style::default()->fg(AnsiColor::Cyan) : Style::default()),
                Span::styled($value === '' && ! $active ? '  required' : '', Style::default()->fg(AnsiColor::Yellow)),
            );
        }
        $lines[] = Line::fromString('');
        if ($form['stage'] === 'edit') {
            $this->drawn['form:submit'] = ['area' => Area::fromScalars($area->left() + 3, $area->top() + 2 + count($lines), 10, 1), 'header' => false];
            $lines[] = Line::fromSpans(Span::styled('  [ Create ]', Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)), Span::styled('   every field is asked for, as node:add prompts for it', $dim));
        } else {
            // While the host key waits for an answer, the first step is done and the second is the question.
            $current = $form['stage'] === 'fingerprint' ? 1 : $form['step'];
            foreach (self::CREATE_STEPS as $index => $step) {
                $state = match (true) {
                    $index < $current || $form['stage'] === 'done' => ['✓ ', AnsiColor::Green],
                    $index === $current => [$form['stage'] === 'fingerprint' ? '? ' : '◌ ', AnsiColor::Cyan],
                    default => ['  ', AnsiColor::DarkGray],
                };
                $lines[] = Line::fromSpans(Span::styled('  '.$state[0], Style::default()->fg($state[1])), Span::styled($step, $index <= $current ? Style::default() : $dim));
                if ($index === 1 && $form['stage'] === 'fingerprint') {
                    $lines[] = Line::fromSpans(Span::styled("      {$form['fields'][1][1]} presents {$form['fingerprint']}", Style::default()->fg(AnsiColor::Yellow)));
                    $lines[] = Line::fromSpans(Span::styled('      Enter trusts it · Esc aborts', $dim));
                }
            }
            if ($form['stage'] === 'done') {
                $lines[] = Line::fromString('');
                $lines[] = Line::fromSpans(Span::styled("  Node {$form['fields'][0][1]} added. ", Style::default()->fg(AnsiColor::Green)), Span::styled('Enter opens it.', $dim));
            }
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)), Span::styled('Create node', Style::default()->addModifier(Modifier::BOLD))))),
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle(Style::default()->fg(AnsiColor::Cyan))
                    ->titles(Title::fromString($form['stage'] === 'edit' ? ' node:add ' : " node:add {$form['fields'][0][1]} "))
                    ->widget(ParagraphWidget::fromText(Text::fromLines(...$lines))),
            );
    }

    // ---- metrics ---------------------------------------------------------------------------

    private function metricsHeight(string $node): int
    {
        return intdiv(count($this->metrics[$node]['cores']) + 3, 4) + 2 + 2;
    }

    /** An htop-like block: cores in four columns, then memory and swap beside the root disk and uptime. */
    private function metricsPanel(string $node, int $width): Widget
    {
        $m = $this->metrics[$node];
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $age = max(0, (int) round(microtime(true) - $this->lastMetrics));
        $inner = $width - 4;
        $gap = 2;
        $column = intdiv($inner - 3 * $gap, 4);
        $lastColumn = $inner - 3 * ($column + $gap);
        $half = intdiv($inner - $gap, 2);
        $lastHalf = $inner - $half - $gap;

        $coreLines = [];
        foreach (array_chunk($m['cores'], 4, true) as $group) {
            $spans = [];
            $position = 0;
            foreach ($group as $core => $load) {
                if ($position > 0) {
                    $spans[] = Span::fromString(str_repeat(' ', $gap));
                }
                $spans = [...$spans, ...$this->bar(str_pad((string) $core, 3), $load, sprintf('%3.0f%%', $load * 100), $position === 3 ? $lastColumn : $column)];
                $position++;
            }
            $coreLines[] = Line::fromSpans(...$spans);
        }
        [$mount, $used, $total] = $m['disks'][0];
        $left = [
            Line::fromSpans(...$this->bar('Mem', $m['mem'][0] / $m['mem'][1], sprintf('%.1fG/%.0fG', $m['mem'][0], $m['mem'][1]), $half)),
            Line::fromSpans(...$this->bar('Swp', $m['swap'][1] > 0 ? $m['swap'][0] / $m['swap'][1] : 0, sprintf('%.1fG/%.0fG', $m['swap'][0], $m['swap'][1]), $half)),
        ];
        $right = [
            Line::fromSpans(...$this->bar(str_pad($mount, 3), $used / $total, sprintf('%.0fG/%.0fG', $used, $total), $lastHalf, [80, 90])),
            Line::fromSpans(Span::styled('Up ', $dim), Span::fromString($m['uptime'])),
        ];

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString(" metrics · polled {$age}s ago · every ".self::METRICS_TICK.'s '))
            ->borderStyle($dim)
            ->padding(Padding::horizontal(1))
            ->widget(
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(Constraint::length(count($coreLines)), Constraint::min(2))
                    ->widgets(
                        ParagraphWidget::fromText(Text::fromLines(...$coreLines)),
                        GridWidget::default()
                            ->direction(Direction::Horizontal)
                            ->constraints(Constraint::length($half), Constraint::length($gap), Constraint::min($lastHalf))
                            ->widgets(ParagraphWidget::fromText(Text::fromLines(...$left)), BlockWidget::default(), ParagraphWidget::fromText(Text::fromLines(...$right))),
                    ),
            );
    }

    /**
     * One htop-style bar: label, [|||||    ], and a reading; green, then yellow, then red past the thresholds.
     *
     * @param  array{int, int}  $thresholds
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

    private function walkMetrics(): void
    {
        $nudge = fn (float $value, float $step, float $min, float $max): float => max($min, min($max, $value + (mt_rand(-100, 100) / 100) * $step));
        foreach ($this->metrics as $node => $m) {
            $this->metrics[$node]['cores'] = array_map(fn (float $c): float => $nudge($c, 0.12, 0.01, 0.99), $m['cores']);
            $this->metrics[$node]['mem'][0] = $nudge($m['mem'][0], 0.3, 0.5, $m['mem'][1] - 0.2);
            $this->metrics[$node]['disks'] = array_map(fn (array $d): array => [$d[0], $nudge($d[1], 0.4, 1, $d[2]), $d[2]], $m['disks']);
        }
    }

    // ---- widgets ---------------------------------------------------------------------------

    /** A small box over the screen listing the actions for the chosen row, at the pointer or in the middle. */
    private function menuPopup(Area $area): Widget
    {
        $menu = $this->menu ?? throw new RuntimeException('No menu is open.');
        $width = self::MENU_WIDTH;
        $lines = [];
        foreach (array_keys($menu['actions']) as $index => $label) {
            $lines[] = Line::fromSpan(Span::styled(str_pad('  '.$label, $width - 2), $index === $menu['selected'] ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()));
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

        return BlockWidget::default()
            ->padding(Padding::fromScalars($left, max(0, $area->width - $width - $left), $top, max(0, $area->height - $height - $top)))
            ->widget($box);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<Constraint>  $widths
     * @param  list<TableRow>  $rows
     */
    private function pane(string $name, string $title, array $headers, array $widths, array $rows, string $empty = 'None.'): Widget
    {
        $focused = $this->focus === $name;
        $hovered = $this->focus === null && $this->hover === $name && $this->form === null;
        $block = BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->borderStyle(match (true) {
                $focused => Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD),
                $hovered => Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD),
                default => Style::default()->fg(AnsiColor::DarkGray),
            });
        if ($title !== '') {
            $block = $block->titles(Title::fromString($title));
        }

        if ($rows === []) {
            return $block->widget(ParagraphWidget::fromString(' '.$empty)->style(Style::default()->fg(AnsiColor::DarkGray)));
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
                ->select(min($this->selected[$name] ?? 0, count($rows) - 1))
                ->highlightSymbol('› ')
                ->highlightStyle($focused ? Style::default()->addModifier(Modifier::REVERSED) : Style::default()->addModifier(Modifier::BOLD)),
        );
    }

    /**
     * A row that turns yellow when the record needs a look: yellow means "inspect".
     *
     * @param  list<string>  $cells
     */
    private function row(array $cells, string $last, bool $warn): TableRow
    {
        return TableRow::fromCells(...array_map(fn (string $cell): TableCell => $this->cell($cell, $warn), [...$cells, $last]));
    }

    private function cell(string $text, bool $warn): TableCell
    {
        $cell = TableCell::fromString($text);
        $cell->style = $warn ? Style::default()->fg(AnsiColor::Yellow) : Style::default();

        return $cell;
    }

    /**
     * Made-up run history for a schedule page, one line per past run.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function scheduleRuns(array $row): array
    {
        $runs = [];
        $step = str_starts_with((string) $row['expression'], '*/5') ? 300 : 86400;
        for ($i = 6; $i >= 1; $i--) {
            $failed = $i === 3 && $row['status'] === 'enabled';
            $runs[] = sprintf('[%s]  %-8s  %s', date('Y-m-d H:i:s', time() - $i * $step), $failed ? 'FAILED' : 'ok', $failed ? 'exit 1 after 12.4 s' : sprintf('exit 0 after %.1f s', 0.8 + ($i * 7) % 5));
        }

        return $runs;
    }

    // ---- fixtures --------------------------------------------------------------------------

    /** Recorded fixtures where they exist; made-up rows fill the rest so every section has data. */
    private function loadData(): void
    {
        $this->nodes = $this->fixture('nodes/node-list/default');
        $this->apps = $this->fixture('apps/app-list/many');
        $this->processes = array_map(fn (array $p): array => [...$p, 'target_type' => 'app_instance'], $this->fixture('processes/process-list/instance'));

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
                $this->instances[] = [
                    'id' => ++$id, 'name' => $environment === 'production' ? 'main' : 'staging', 'environment' => $environment,
                    'app' => ['id' => $app['id'], 'name' => $app['name'], 'slug' => $app['slug']],
                    'node' => ['id' => $node['id'], 'name' => $node['name']],
                    'domain' => ($environment === 'production' ? '' : 'staging.').$app['slug'].'.test',
                    'status' => $index === 4 ? 'degraded' : 'active',
                ];
                foreach (['queue', 'scheduler'] as $process) {
                    $this->processes[] = ['id' => ++$processId, 'target_type' => 'app_instance', 'target_id' => $id, 'name' => $process, 'runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'running', 'status' => 'active'];
                }
                $this->schedules[] = ['id' => count($this->schedules) + 1, 'instance_id' => $id, 'name' => 'backup', 'command' => 'php artisan backup:run --only-db', 'expression' => '0 3 * * *', 'next_run' => 'tomorrow 03:00', 'status' => 'enabled'];
            }
        }
        $this->schedules[] = ['id' => count($this->schedules) + 1, 'instance_id' => 1, 'name' => 'horizon-snapshot', 'command' => 'php artisan horizon:snapshot', 'expression' => '*/5 * * * *', 'next_run' => 'in 3 minutes', 'status' => 'enabled'];
        $this->schedules[] = ['id' => count($this->schedules) + 1, 'instance_id' => 1, 'name' => 'prune-logs', 'command' => 'find storage/logs -mtime +14 -delete', 'expression' => '0 4 * * 0', 'next_run' => 'Sunday 04:00', 'status' => 'disabled'];

        foreach ($this->nodes as $node) {
            $gateway = in_array('gateway', $node['roles'], true);
            $services = $gateway ? ['orbit-dns' => null, 'wg-easy' => null] : ['postgres' => 'PostgreSQL 16', 'redis' => 'Redis 7'];
            foreach ($services as $service => $engine) {
                $this->processes[] = ['id' => ++$processId, 'target_type' => 'node', 'target_id' => 0, 'node' => $node['name'], 'name' => $service, ...$engine === null ? [] : ['engine' => $engine], 'runtime' => 'docker', 'working_directory' => '/srv/orbit/services/'.$service, 'restart_policy' => 'always', 'desired_state' => 'running', 'runtime_status' => 'running', 'status' => 'active'];
            }
            $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '22/tcp', 'action' => 'allow', 'source' => '10.44.0.0/16', 'status' => 'applied'];
            $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '443/tcp', 'action' => 'allow', 'source' => 'any', 'status' => 'applied'];
            if ($gateway) {
                $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $node['name'], 'port' => '51820/udp', 'action' => 'allow', 'source' => 'any', 'status' => 'applied'];
            }
            $this->metrics[$node['name']] = [
                'cores' => $gateway ? [0.08, 0.05, 0.11, 0.04] : [0.42, 0.37, 0.55, 0.28, 0.61, 0.33, 0.47, 0.39],
                'mem' => $gateway ? [1.4, 4] : [9.8, 16],
                'swap' => $gateway ? [0.0, 2] : [0.3, 4],
                'uptime' => $gateway ? '41 days, 3:12' : '12 days, 17:40',
                'disks' => $gateway ? [['/', 9, 40]] : [['/', 31, 80]],
            ];
        }

        $this->databases = [
            [
                'slug' => 'charlie-shop', 'driver' => 'pgsql', 'node' => 'app-dev', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'charlie_shop', 'username' => 'charlie_shop', 'process' => 'postgres on app-dev',
                'targets' => [1, 2, 3],
                'users' => [
                    ['username' => 'charlie_shop', 'privileges' => 'owner', 'used_by' => 'charlie-shop/dev, staging', 'created' => '2026-08-02'],
                    ['username' => 'charlie_shop_ro', 'privileges' => 'read-only', 'used_by' => 'metrics exporter', 'created' => '2026-09-01'],
                    ['username' => 'nick', 'privileges' => 'superuser', 'used_by' => '—', 'created' => '2026-07-14'],
                ],
                'tables' => [['name' => 'users', 'rows' => '12 480', 'size' => '9.1 MB'], ['name' => 'orders', 'rows' => '88 102', 'size' => '61 MB'], ['name' => 'order_items', 'rows' => '301 774', 'size' => '140 MB'], ['name' => 'jobs', 'rows' => '14', 'size' => '96 kB']],
            ],
            [
                'slug' => 'acme', 'driver' => 'pgsql', 'node' => 'app-dev', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'acme', 'username' => 'acme', 'process' => 'postgres on app-dev',
                'targets' => [4, 5],
                'users' => [['username' => 'acme', 'privileges' => 'owner', 'used_by' => 'acme/dev, main', 'created' => '2026-08-20']],
                'tables' => [['name' => 'users', 'rows' => '2 310', 'size' => '1.8 MB'], ['name' => 'invoices', 'rows' => '9 904', 'size' => '12 MB']],
            ],
            [
                'slug' => 'bravo-docs', 'driver' => 'sqlite', 'node' => 'gateway', 'path' => '/srv/orbit/apps/bravo-docs/main/database.sqlite',
                'targets' => [6],
                'users' => [],
                'tables' => [['name' => 'pages', 'rows' => '412', 'size' => '2.2 MB']],
            ],
        ];

        $this->instanceLogs = [
            '['.date('Y-m-d H:i:s', time() - 8).'] local.INFO: Deployed 4f2c9a1 (main) in 41 s',
            '['.date('Y-m-d H:i:s', time() - 5).'] local.INFO: GET / 200 in 88 ms',
            '['.date('Y-m-d H:i:s', time() - 2).'] local.WARNING: Queue lag 14 s on default',
        ];
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
