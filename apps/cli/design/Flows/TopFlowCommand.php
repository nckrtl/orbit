<?php

declare(strict_types=1);

namespace Design\Flows;

use App\Commands\GatewayCommand;
use App\Support\Console\Renderers\TableTheme;
use Design\Support\AnsiLine;
use Design\Support\PanelConfirmPrompt;
use Design\Support\PanelSelectPrompt;
use Design\Support\PanelTextPrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Themes\Default\ConfirmPromptRenderer;
use Laravel\Prompts\Themes\Default\SelectPromptRenderer;
use Laravel\Prompts\Themes\Default\TextPromptRenderer;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
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
     * The node create form: the Laravel Prompts asked so far (the last one active), the answers,
     * and how far the creation got.
     *
     * @var array{prompts: list<array{string, PanelTextPrompt|PanelSelectPrompt}>, values: array<string, string>}|null
     */
    private ?array $form = null;

    /**
     * A node being added: which step runs, and the host key question while it waits for an answer.
     *
     * @var array{node: string, stage: string, step: int, stepAt: float, fingerprint: string, host: string, confirm: PanelConfirmPrompt|null}|null
     */
    private ?array $provisioning = null;

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
                        } elseif ($this->pressInProvisioning($event->char)) {
                            // The host key question took the key.
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
                    if ($event instanceof TerminalResizedEvent) {
                        // The display is sized once; a resized terminal needs a new one and a clean screen.
                        $display = DisplayBuilder::default()->fullscreen()->build();
                        $display->clear();
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
        $mapped = match ($code) {
            KeyCode::Enter => Key::ENTER,
            KeyCode::Left => Key::LEFT,
            KeyCode::Right => Key::RIGHT,
            KeyCode::Esc => 'n',
            default => null,
        };
        if ($mapped !== null && $this->pressInProvisioning($mapped === 'n' ? Key::RIGHT : $mapped)) {
            if ($mapped === 'n') {
                $this->pressInProvisioning(Key::ENTER);
            }

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
            if ($event->kind === MouseEventKind::Down && $event->button === MouseButton::Left && $this->hitRow('back', $x, $y) !== null) {
                $this->form = null;
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
        if ($this->focus === 'tables') {
            $db = $this->page()['row'] ?? [];
            $this->open('tables', [...$row, 'database' => $db['slug'], 'driver' => $db['driver']]);

            return;
        }
        if (in_array($this->focus, ['users', 'keyspace', 'slowlog'], true)) {
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
            'tables' => [
                'describe' => "database:describe {$row['database']} {$row['name']}",
                'count' => "database:query {$row['database']} \"SELECT count(*) FROM {$row['name']}\"",
                'query' => "database:query {$row['database']}",
            ],
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

    private const array FORM_PROMPTS = ['name', 'host', 'port', 'user', 'roles', 'tld'];

    /** The form asks the node:add prompts one by one, drawn by the CLI's own theme inside the panel. */
    private function openForm(): void
    {
        // The theme finds a renderer by concrete class, so the panel subclasses reuse the CLI's renderers.
        TableTheme::extend([PanelTextPrompt::class => TextPromptRenderer::class, PanelSelectPrompt::class => SelectPromptRenderer::class, PanelConfirmPrompt::class => ConfirmPromptRenderer::class]);
        Prompt::addTheme('orbit-cli', TableTheme::renderers());
        Prompt::theme('orbit-cli');
        $this->form = ['prompts' => [], 'values' => []];
        $this->focus = null;
        $this->askNext();
    }

    /** Builds the next prompt, with the same labels, defaults, and validation node:add uses. */
    private function askNext(): void
    {
        if ($this->form === null) {
            return;
        }
        $key = self::FORM_PROMPTS[count($this->form['prompts'])] ?? null;
        if ($key === null) {
            $this->startProvisioning();

            return;
        }
        $prompt = match ($key) {
            'name' => PanelTextPrompt::make(label: 'Node name', placeholder: 'beast', required: true, validate: fn (string $v): ?string => preg_match('/^[a-z0-9-]+$/', $v) === 1 ? null : 'Use lowercase letters, digits, and dashes.'),
            'host' => PanelTextPrompt::make(label: 'SSH host', placeholder: '10.0.0.12 or beast.example.test', required: true),
            'port' => PanelTextPrompt::make(label: 'SSH port', default: '22', required: true, validate: fn (string $v): ?string => ctype_digit($v) && (int) $v > 0 && (int) $v < 65536 ? null : 'A port is a number from 1 to 65535.'),
            'user' => PanelTextPrompt::make(label: 'SSH user', default: 'root', required: true),
            'roles' => PanelSelectPrompt::make(label: 'Role', options: ['app-dev' => 'app-dev · runs App instances', 'gateway' => 'gateway · runs the Gateway and the VPN hub', 'app-prod' => 'app-prod · runs production App instances'], default: 'app-dev'),
            'tld' => PanelTextPrompt::make(label: 'TLD for its domains', default: 'test', required: true),
        };
        $this->form['prompts'][] = [$key, $prompt];
    }

    private function typeInForm(string $char): void
    {
        $this->pressInForm($char);
    }

    private function keyInForm(KeyCode $code): void
    {
        if ($this->form === null) {
            return;
        }
        if ($code === KeyCode::Esc) {
            $this->form = null;

            return;
        }
        $key = match ($code) {
            KeyCode::Enter => Key::ENTER,
            KeyCode::Backspace => Key::BACKSPACE,
            KeyCode::Delete => Key::DELETE,
            KeyCode::Up => Key::UP,
            KeyCode::Down => Key::DOWN,
            KeyCode::Left => Key::LEFT,
            KeyCode::Right => Key::RIGHT,
            KeyCode::Home => Key::HOME,
            KeyCode::End => Key::END,
            default => null,
        };
        if ($key !== null) {
            $this->pressInForm($key);
        }
    }

    /** Keys go to the active prompt; a submitted prompt records its answer and the next one is asked. */
    private function pressInForm(string $key): void
    {
        if ($this->form === null) {
            return;
        }
        [$name, $prompt] = $this->form['prompts'][count($this->form['prompts']) - 1];
        $prompt->press($key);
        if ($prompt->done()) {
            $this->form['values'][$name] = (string) $prompt->value();
            $this->askNext();
        }
    }

    /** The answered prompts become a node in provisioning; its page opens and shows the steps as they run. */
    private function startProvisioning(): void
    {
        if ($this->form === null) {
            return;
        }
        $values = $this->form['values'];
        $node = [
            'id' => count($this->nodes) + 1,
            'name' => $values['name'],
            'status' => 'provisioning',
            'roles' => [$values['roles']],
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => $values['tld'],
            'public_ssh_host' => $values['host'],
            'public_ssh_port' => (int) $values['port'],
            'user' => $values['user'],
            'wireguard_ip' => null,
            'lan_ip' => null,
        ];
        $this->nodes[] = $node;
        $this->provisioning = ['node' => $node['name'], 'stage' => 'steps', 'step' => 0, 'stepAt' => microtime(true), 'fingerprint' => 'SHA256:Qm3fL9xTz1a8YhVw2pR7dKcN4bE6sJ0uGiXo5mHt2Ac', 'host' => $values['host'], 'confirm' => null];
        $this->ran = "orbit node:add {$node['name']} --host {$values['host']}";
        $this->form = null;
        $this->open('nodes', $node);
    }

    /** Steps advance on their own; the second one stops to ask about the host key, as node:add does. */
    private function advanceForm(): void
    {
        $p = $this->provisioning;
        if ($p === null || $p['stage'] !== 'steps' || microtime(true) - $p['stepAt'] < 0.9) {
            return;
        }
        $next = $p['step'] + 1;
        if ($next === 1) {
            $this->provisioning['stage'] = 'fingerprint';
            $this->provisioning['confirm'] = PanelConfirmPrompt::make(label: "{$p['host']} presents host key {$p['fingerprint']}. Trust it?", default: true, yes: 'Trust', no: 'Abort');

            return;
        }
        if ($next >= count(self::CREATE_STEPS)) {
            $this->finishProvisioning();

            return;
        }
        $this->provisioning['step'] = $next;
        $this->provisioning['stepAt'] = microtime(true);
    }

    /** Keys while the host key question waits go to its confirm prompt. */
    private function pressInProvisioning(string $key): bool
    {
        $p = $this->provisioning;
        if ($p === null || $p['stage'] !== 'fingerprint' || $p['confirm'] === null) {
            return false;
        }
        $p['confirm']->press($key);
        if ($p['confirm']->done()) {
            if ($p['confirm']->value() === true) {
                $this->provisioning['stage'] = 'steps';
                $this->provisioning['step'] = 1;
                $this->provisioning['stepAt'] = microtime(true);
            } else {
                $this->provisioning['stage'] = 'failed';
                $this->updateNode($p['node'], ['status' => 'failed', 'failed_step' => 'trust-host-key', 'error_code' => 'host-key-rejected']);
            }
        }

        return true;
    }

    /** The node becomes active, with an address, metrics, and its first firewall rule. */
    private function finishProvisioning(): void
    {
        $p = $this->provisioning;
        if ($p === null) {
            return;
        }
        $this->updateNode($p['node'], ['status' => 'active', 'wireguard_ip' => '10.44.0.'.(9 + count($this->nodes))]);
        $this->metrics[$p['node']] = ['cores' => [0.03, 0.02, 0.04, 0.02], 'mem' => [0.9, 8], 'swap' => [0.0, 2], 'uptime' => '0 days, 0:01', 'disks' => [['/', 6, 80]]];
        $this->firewall[] = ['id' => count($this->firewall) + 1, 'node' => $p['node'], 'port' => '22/tcp', 'action' => 'allow', 'source' => '10.44.0.0/16', 'status' => 'applied'];
        $this->provisioning = null;
    }

    /** @param  array<string, mixed>  $changes */
    private function updateNode(string $name, array $changes): void
    {
        foreach ($this->nodes as $index => $node) {
            if ($node['name'] === $name) {
                $this->nodes[$index] = [...$node, ...$changes];
                foreach ($this->pages as $i => $page) {
                    if ($page['kind'] === 'nodes' && $page['row']['name'] === $name) {
                        $this->pages[$i]['row'] = $this->nodes[$index];
                    }
                }
            }
        }
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
            'users', 'tables', 'keyspace', 'slowlog' => ($page['kind'] ?? '') === 'databases' ? ($page['row'][$pane] ?? []) : [],
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
            'tables' => isset($row['schema']) ? "{$row['schema']}.{$row['name']}" : $row['name'],
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
            'databases' => match ($row['driver']) {
                'sqlite' => ['Slug' => $row['slug'], 'Driver' => 'sqlite', 'Node' => $row['node'], 'Path' => $row['path'], 'Size' => $row['size'], 'Journal' => $row['journal']],
                'redis' => ['Slug' => $row['slug'], 'Driver' => 'redis', 'Node' => $row['node'], 'Host' => "{$row['host']}:{$row['port']}", 'Database' => $row['database'], 'Auth' => $row['username'] ?? 'default user', 'Password' => '••••••••', 'Version' => $row['version'], 'Server process' => $row['process']],
                default => ['Slug' => $row['slug'], 'Driver' => $row['driver'], 'Node' => $row['node'], 'Host' => "{$row['host']}:{$row['port']}", 'Database' => $row['database'], 'Username' => $row['username'], 'Password' => '••••••••', 'Version' => $row['version'], 'Server process' => $row['process']],
            },
            'processes' => ['Name' => $row['name'], ...isset($row['engine']) ? ['Engine' => $row['engine']] : [], 'Owner' => $this->processOwner($row), 'Node' => $this->processNode($row), 'Runtime' => $row['runtime'], 'Working directory' => $row['working_directory'] ?? null, 'Restart policy' => $row['restart_policy'] ?? null, 'Desired state' => $row['desired_state'], 'Runtime status' => $row['runtime_status']],
            'schedules' => ['Name' => $row['name'], 'Instance' => $this->instanceName($row['instance_id']), 'Node' => $this->instanceNode($row['instance_id']), 'Command' => $row['command'], 'Expression' => $row['expression'], 'Next run' => $row['next_run'], 'Status' => $row['status']],
            'firewall' => ['Port' => $row['port'], 'Action' => $row['action'], 'Source' => $row['source'], 'Status' => $row['status'], 'Node' => $row['node']],
            'tables' => ['Database' => $row['database'], ...isset($row['schema']) ? ['Schema' => $row['schema']] : [], 'Table' => $row['name'], ...isset($row['engine']) ? ['Engine' => $row['engine']] : [], 'Rows' => $row['rows'], 'Size' => $row['size'], 'Columns' => (string) count($this->tableColumns($row['name']))],
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
            $this->form !== null => '  answer the prompt · Enter confirms · Esc cancels',
            $this->provisioning !== null && $this->provisioning['stage'] === 'fingerprint' => '  ←→ or y/n · Enter confirms · Esc aborts the add',
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
                array_map(fn (array $d): TableRow => $this->row([$d['slug'], $d['driver'], $d['node'] ?? '—', isset($d['host']) ? "{$d['host']}:{$d['port']}" : $d['path'], $d['database'] ?? '—', (string) count($d['targets'])], isset($d['users']) ? (string) count($d['users']) : '—', false), $rows),
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
            'tables' => 'Table',
            'firewall' => 'Firewall rule',
            default => '',
        };
        $crumbs = ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(
            Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)),
            Span::styled("{$label}: ", $dim),
            Span::styled($this->rowTitle($kind, $row), Style::default()->addModifier(Modifier::BOLD)),
        )));

        // Properties first; App and Node values are links to those records' pages.
        $propertiesWidth = in_array($kind, ['nodes', 'instances', 'databases', 'tables'], true) ? intdiv($body->width * 40, 100) : $body->width;
        $propertyRows = [];
        $index = 0;
        foreach ($this->properties($kind, $row) as $name => $value) {
            $warn = in_array($name, ['Runtime status', 'Status'], true) && ! in_array($value, ['active', 'running', 'enabled', 'applied'], true);
            $link = in_array($name, ['App', 'Node'], true) && $value !== '—';
            if ($link) {
                $this->drawn['link:'.strtolower($name)] = ['area' => Area::fromScalars($body->left() + 2, $body->top() + 1 + $index, max(10, $propertiesWidth - 4), 1), 'header' => false];
            }
            $valueCell = $link ? TableCell::fromLine(Line::fromSpan(Span::styled($value, Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::UNDERLINED)))) : $this->cell($value, $warn);
            $propertyRows[] = TableRow::fromCells(TableCell::fromLine(Line::fromSpan(Span::styled($name, $dim))), $valueCell);
            $index++;
        }
        $table = TableWidget::default()->widths(Constraint::length(18), Constraint::min(10))->rows(...$propertyRows);
        $table->columnSpacing = 1;
        $properties = BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Properties '))->borderStyle($dim)->padding(Padding::horizontal(1))->widget($table);
        $propertiesHeight = count($propertyRows) + 2;

        $content = match ($kind) {
            'nodes' => $this->nodePage($row, $properties, $propertiesHeight, $body),
            'apps' => $this->appPage($properties, $propertiesHeight, $body),
            'instances' => $this->instancePage($properties, $propertiesHeight, $body),
            'databases' => $this->databasePage($properties, $propertiesHeight, $body),
            'tables' => $this->tablePage($row, $properties, $propertiesHeight, $body),
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
        $live = isset($this->metrics[$node['name']]);
        $metricsHeight = $live ? $this->metricsHeight($node['name']) : count(self::CREATE_STEPS) + 6;
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
                    ->widgets($properties, $live ? $this->metricsPanel($node['name'], $topColumns->get(1)->width) : $this->provisioningPanel($node)),
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
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString(' Logs '))->borderStyle($dim)->padding(Padding::horizontal(1))
                    ->widget(ParagraphWidget::fromString(implode("\n", array_slice($this->instanceLogs, -max(1, $rows->get(1)->height - 2))))),
            );
    }

    /**
     * A database page is bespoke to its engine: what it lists and which numbers matter differ
     * between PostgreSQL, MySQL, Redis, and SQLite. All start with the properties beside the
     * attached instances and a one-line stats block for the server.
     */
    private function databasePage(Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $db = $this->page()['row'] ?? [];
        $targets = $this->rowsFor('targets');
        $top = max($propertiesHeight, count($targets) + 3);

        [$stats, $panes, $extra] = match ($db['driver']) {
            'pgsql' => [
                sprintf('Connections %d/%d · %d active · %d idle · %d waiting   Cache hit %.1f%%   Size %s   WAL %s   Replication %s', ...$db['stats']),
                [
                    ['users', ' Roles ', ['Role', 'Privileges', 'Used by', 'Created'], [26, 22, 32, 20], fn (array $u): array => [[$u['username'], $u['privileges'], $u['used_by']], $u['created'], $u['used_by'] === '—'], 55],
                    ['tables', ' Tables ', ['Schema', 'Table', 'Rows', 'Size'], [18, 36, 22, 24], fn (array $r): array => [[$r['schema'], $r['name'], $r['rows']], $r['size'], false], 45],
                ],
                null,
            ],
            'mysql' => [
                sprintf('Threads %d connected · %d running   Slow queries %d   InnoDB buffer pool hit %.1f%%   Size %s   Binlog %s', ...$db['stats']),
                [
                    ['users', ' Users ', ['User', 'Host', 'Privileges', 'Used by'], [24, 14, 30, 32], fn (array $u): array => [[$u['username'], $u['host'], $u['privileges']], $u['used_by'], $u['used_by'] === '—'], 55],
                    ['tables', ' Tables ', ['Table', 'Engine', 'Rows', 'Size'], [40, 18, 20, 22], fn (array $r): array => [[$r['name'], $r['engine'], $r['rows']], $r['size'], false], 45],
                ],
                null,
            ],
            'redis' => [
                sprintf('Memory %s / %s   Clients %d   Ops/s %s   Hit rate %.1f%%   Evictions %d   Persistence %s', ...$db['stats']),
                [
                    ['keyspace', ' Keyspace ', ['DB', 'Keys', 'With TTL', 'Avg TTL'], [16, 28, 28, 28], fn (array $k): array => [[$k['db'], $k['keys'], $k['expires']], $k['avg_ttl'], false], 40],
                    ['users', ' ACL users ', ['User', 'Rules', 'Used by'], [22, 46, 32], fn (array $u): array => [[$u['username'], $u['privileges']], $u['used_by'], $u['used_by'] === '—'], 60],
                ],
                ['slowlog', ' Slow log ', ['When', 'Duration', 'Command'], [22, 14, 64], fn (array $s): array => [[$s['at'], $s['duration']], $s['command'], true]],
            ],
            default => [
                sprintf('File %s   Journal %s   Page size %s   Tables %d', $db['size'], $db['journal'], $db['page_size'], count($db['tables'])),
                [
                    ['tables', ' Tables ', ['Table', 'Rows', 'Size'], [50, 24, 24], fn (array $r): array => [[$r['name'], $r['rows']], $r['size'], false], 100],
                ],
                null,
            ],
        };

        $constraints = [Constraint::length($top), Constraint::length(3), $extra === null ? Constraint::min(5) : Constraint::percentage(45), ...$extra === null ? [] : [Constraint::min(4)]];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $topColumns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(60)])->split($rows->get(0));
        $this->drawn['targets'] = ['area' => $topColumns->get(1), 'header' => true];
        $this->paneOrder = ['targets'];

        $sideWidgets = [];
        $sideConstraints = [];
        $sideAreas = Layout::default()->direction(Direction::Horizontal)->constraints(array_map(fn (array $pane): Constraint => Constraint::percentage($pane[5]), $panes))->split($rows->get(2));
        foreach ($panes as $index => [$name, $title, $headers, $widths, $mapper, $share]) {
            $this->drawn[$name] = ['area' => $sideAreas->get($index), 'header' => true];
            $this->paneOrder[] = $name;
            $sideConstraints[] = Constraint::percentage($share);
            $sideWidgets[] = $this->pane($name, $title, $headers, array_map(fn (int $w): Constraint => Constraint::percentage($w), $widths), array_map(fn (array $r): TableRow => $this->row(...$mapper($r)), $this->rowsFor($name)), $name === 'users' ? 'No users recorded. database:user:create records the next one.' : 'None.');
        }
        $widgets = [
            GridWidget::default()
                ->direction(Direction::Horizontal)
                ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                ->widgets(
                    $properties,
                    $this->pane('targets', ' Attached instances ', ['App', 'Name', 'Node', 'Prefix', 'Status'], [Constraint::percentage(24), Constraint::percentage(20), Constraint::percentage(18), Constraint::percentage(20), Constraint::percentage(14)], array_map(fn (array $i): TableRow => $this->row([$i['app']['slug'], $i['name'], $i['node']['name'], $i['name'] === 'dev' ? '' : "{$i['name']}_"], $i['status'], $i['status'] !== 'active'), $targets)),
                ),
            BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle(Style::default()->fg(AnsiColor::DarkGray))
                ->titles(Title::fromString(' '.$this->engineName($db['driver']).' '.$db['version'].' · polled 5s ago '))
                ->padding(Padding::horizontal(1))
                ->widget(ParagraphWidget::fromString($stats)),
            GridWidget::default()->direction(Direction::Horizontal)->constraints(...$sideConstraints)->widgets(...$sideWidgets),
        ];
        if ($extra !== null) {
            [$name, $title, $headers, $widths, $mapper] = $extra;
            $this->drawn[$name] = ['area' => $rows->get(3), 'header' => true];
            $this->paneOrder[] = $name;
            $widgets[] = $this->pane($name, $title, $headers, array_map(fn (int $w): Constraint => Constraint::percentage($w), $widths), array_map(fn (array $r): TableRow => $this->row(...$mapper($r)), $this->rowsFor($name)));
        }

        return GridWidget::default()->direction(Direction::Vertical)->constraints(...$constraints)->widgets(...$widgets);
    }

    /**
     * A table page: properties beside the columns (what database:describe shows), then a five-row
     * sample. A sample too wide for the pane is shown one record per block instead of as a grid.
     *
     * @param  array<string, mixed>  $table
     */
    private function tablePage(array $table, Widget $properties, int $propertiesHeight, Area $body): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $columns = $this->tableColumns($table['name']);
        $sample = $this->tableSample($table['name']);
        $top = max($propertiesHeight, count($columns) + 3);
        $constraints = [Constraint::length($top), Constraint::min(5)];
        $rows = Layout::default()->direction(Direction::Vertical)->constraints($constraints)->split($body);
        $topColumns = Layout::default()->direction(Direction::Horizontal)->constraints([Constraint::percentage(40), Constraint::percentage(60)])->split($rows->get(0));
        $this->drawn['columns'] = ['area' => $topColumns->get(1), 'header' => true];
        $this->paneOrder = ['columns'];

        // Widest value per column decides whether the grid fits; one cell of spacing between columns.
        $names = array_column($columns, 'name');
        $widths = array_map(fn (string $name): int => max(mb_strlen($name), ...array_map(fn (array $r): int => mb_strlen($r[$name]), $sample)), $names);
        $fits = array_sum($widths) + count($widths) + 3 <= $rows->get(1)->width - 2;

        if ($fits) {
            $this->drawn['sample'] = ['area' => $rows->get(1), 'header' => true];
            $this->paneOrder[] = 'sample';
            $total = max(1, array_sum($widths));
            $sampleWidget = $this->pane('sample', ' Sample · first 5 rows ', $names, array_map(fn (int $w): Constraint => Constraint::percentage(max(4, intdiv($w * 96, $total))), $widths), array_map(fn (array $r): TableRow => $this->row(array_slice(array_values($r), 0, -1), (string) end($r), false), $sample));
        } else {
            $lines = [];
            foreach ($sample as $index => $record) {
                $lines[] = Line::fromSpans(Span::styled('row '.($index + 1), Style::default()->addModifier(Modifier::BOLD)));
                foreach ($record as $name => $value) {
                    $lines[] = Line::fromSpans(Span::styled('  '.str_pad($name, 18), $dim), Span::fromString($value));
                }
                $lines[] = Line::fromString('');
            }
            $sampleWidget = BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle($dim)->padding(Padding::horizontal(1))
                ->titles(Title::fromString(' Sample · first 5 rows · '.count($columns).' columns do not fit side by side, so one record per block '))
                ->widget(ParagraphWidget::fromText(Text::fromLines(...array_slice($lines, 0, max(1, $rows->get(1)->height - 2)))));
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(Constraint::percentage(40), Constraint::percentage(60))
                    ->widgets(
                        $properties,
                        $this->pane('columns', ' Columns ', ['Name', 'Type', 'Nullable', 'Default', 'Key'], [Constraint::percentage(28), Constraint::percentage(26), Constraint::percentage(12), Constraint::percentage(22), Constraint::percentage(12)], array_map(fn (array $c): TableRow => $this->row([$c['name'], $c['type'], $c['nullable'] ? 'yes' : 'no', $c['default']], $c['key'], false), $columns)),
                    ),
                $sampleWidget,
            );
    }

    /**
     * Made-up schemas per table name, shaped like database:describe output.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string, key: string}>
     */
    private function tableColumns(string $table): array
    {
        $col = fn (string $name, string $type, bool $nullable = false, string $default = '—', string $key = ''): array => ['name' => $name, 'type' => $type, 'nullable' => $nullable, 'default' => $default, 'key' => $key];

        return match ($table) {
            'users' => [$col('id', 'bigint', key: 'PK'), $col('name', 'varchar(255)'), $col('email', 'varchar(255)', key: 'UNIQUE'), $col('email_verified_at', 'timestamp', true), $col('password', 'varchar(255)'), $col('remember_token', 'varchar(100)', true), $col('last_login_ip', 'inet', true), $col('preferences', 'jsonb', false, "'{}'"), $col('created_at', 'timestamp', true), $col('updated_at', 'timestamp', true)],
            'orders' => [$col('id', 'bigint', key: 'PK'), $col('user_id', 'bigint', key: 'FK users'), $col('status', 'varchar(32)', false, "'pending'"), $col('total', 'numeric(10,2)'), $col('currency', 'char(3)', false, "'EUR'"), $col('placed_at', 'timestamp')],
            'order_items' => [$col('id', 'bigint', key: 'PK'), $col('order_id', 'bigint', key: 'FK orders'), $col('sku', 'varchar(64)'), $col('quantity', 'integer', false, '1'), $col('unit_price', 'numeric(10,2)')],
            'jobs' => [$col('id', 'bigint', key: 'PK'), $col('queue', 'varchar(255)', key: 'INDEX'), $col('payload', 'longtext'), $col('attempts', 'tinyint', false, '0'), $col('reserved_at', 'integer', true), $col('available_at', 'integer'), $col('created_at', 'integer')],
            default => [$col('id', 'bigint', key: 'PK'), $col('name', 'varchar(255)'), $col('created_at', 'timestamp', true), $col('updated_at', 'timestamp', true)],
        };
    }

    /**
     * Five made-up rows per table, the result a fixed SELECT … LIMIT 5 would give.
     *
     * @return list<array<string, string>>
     */
    private function tableSample(string $table): array
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = match ($table) {
                'users' => ['id' => (string) $i, 'name' => ['Ada Lovelace', 'Grace Hopper', 'Linus Torvalds', 'Margaret Hamilton', 'Ken Thompson'][$i - 1], 'email' => "user{$i}@charlie-shop.test", 'email_verified_at' => '2026-08-0'.$i.' 09:12:00', 'password' => '$2y$12$…', 'remember_token' => $i % 2 ? 'k9F…' : 'NULL', 'last_login_ip' => "10.44.0.{$i}", 'preferences' => '{"theme":"dark","newsletter":true}', 'created_at' => '2026-08-0'.$i.' 09:11:58', 'updated_at' => '2026-09-1'.$i.' 17:40:02'],
                'orders' => ['id' => (string) (1000 + $i), 'user_id' => (string) $i, 'status' => ['paid', 'pending', 'paid', 'shipped', 'refunded'][$i - 1], 'total' => sprintf('%.2f', 19.5 * $i), 'currency' => 'EUR', 'placed_at' => "2026-09-1{$i} 14:0{$i}:00"],
                'order_items' => ['id' => (string) (5000 + $i), 'order_id' => (string) (1000 + $i), 'sku' => "CS-00{$i}", 'quantity' => (string) $i, 'unit_price' => '19.50'],
                'jobs' => ['id' => (string) (4200 + $i), 'queue' => 'default', 'payload' => '{"uuid":"9d1e…","displayName":"App\\Jobs\\SyncOrders","job":"Illuminate\\Queue\\CallQueuedHandler@call"}', 'attempts' => '0', 'reserved_at' => 'NULL', 'available_at' => (string) (1789700000 + $i), 'created_at' => (string) (1789700000 + $i)],
                default => ['id' => (string) $i, 'name' => "record {$i}", 'created_at' => "2026-09-0{$i} 10:00:00", 'updated_at' => "2026-09-0{$i} 10:00:00"],
            };
        }

        return $rows;
    }

    /**
     * While a node is being added, its page shows the steps where the metrics will be.
     *
     * @param  array<string, mixed>  $node
     */
    private function provisioningPanel(array $node): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);
        $p = $this->provisioning !== null && $this->provisioning['node'] === $node['name'] ? $this->provisioning : null;
        $asking = $p !== null && $p['stage'] === 'fingerprint';
        $current = $p === null ? -1 : ($asking ? 1 : $p['step']);
        $failed = $node['status'] === 'failed';

        $lines = [];
        foreach (self::CREATE_STEPS as $index => $step) {
            $state = match (true) {
                $failed && $index === 1 => ['✗ ', AnsiColor::Red],
                $index < $current => ['✓ ', AnsiColor::Green],
                $index === $current => [$asking ? '? ' : '◌ ', AnsiColor::Cyan],
                default => ['  ', AnsiColor::DarkGray],
            };
            $lines[] = Line::fromSpans(Span::styled($state[0], Style::default()->fg($state[1])), Span::styled($step, $index <= $current || $failed ? Style::default() : $dim));
            if ($index === 1 && $asking && $p['confirm'] !== null) {
                foreach (explode("\n", rtrim($p['confirm']->frame(), "\n")) as $text) {
                    $lines[] = AnsiLine::parse('  '.$text);
                }
            }
        }
        if ($failed) {
            $lines[] = Line::fromString('');
            $lines[] = Line::fromSpans(Span::styled("Host key of {$node['public_ssh_host']} was not trusted; the add stopped. ", Style::default()->fg(AnsiColor::Yellow)), Span::styled('Remove the node or retry with node:add.', $dim));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)->borderType(BorderType::Rounded)
            ->titles(Title::fromString($failed ? ' add failed ' : ' provisioning · node:add '))
            ->borderStyle(Style::default()->fg($failed ? AnsiColor::Yellow : AnsiColor::Cyan))
            ->padding(Padding::horizontal(1))
            ->widget(ParagraphWidget::fromText(Text::fromLines(...$lines)));
    }

    private function engineName(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'redis' => 'Redis',
            default => 'SQLite',
        };
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
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->titles(Title::fromString($title))->borderStyle($dim)->padding(Padding::horizontal(1))
                    ->widget(ParagraphWidget::fromString(implode("\n", array_slice($lines, -$tail)))),
            );
    }

    /** The node create form: the prompts asked so far as their theme draws them, then the steps and the result. */
    private function formPage(Area $area): Widget
    {
        $form = $this->form ?? throw new RuntimeException('No form is open.');
        $this->drawn['back'] = ['area' => Area::fromScalars($area->left(), $area->top(), 10, 1), 'header' => false];

        $lines = [];
        foreach ($form['prompts'] as [$key, $prompt]) {
            foreach (explode("\n", rtrim($prompt->frame(), "\n")) as $text) {
                $lines[] = AnsiLine::parse($text);
            }
        }

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::min(5))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLines(Line::fromSpans(Span::styled('  ‹ back  ', Style::default()->fg(AnsiColor::Cyan)), Span::styled('Create node', Style::default()->addModifier(Modifier::BOLD))))),
                BlockWidget::default()->borders(Borders::ALL)->borderType(BorderType::Rounded)->borderStyle(Style::default()->fg(AnsiColor::Cyan))
                    ->titles(Title::fromString(' node:add '))
                    ->padding(Padding::horizontal(1))
                    ->widget(ParagraphWidget::fromText(Text::fromLines(...array_slice($lines, 0, max(1, $area->height - 4))))),
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

        // The last column is right-aligned: its cells are padded to the column's width, which the
        // same layout split the table renderer uses gives us.
        $lastWidth = $this->lastColumnWidth($name, $widths);
        $rows = array_map(fn (TableRow $row): TableRow => $this->alignLast($row, $lastWidth), $rows);

        $table = TableWidget::default();
        $table->columnSpacing = 1;
        if ($headers !== []) {
            $table->header($this->alignLast(TableRow::fromStrings(...$headers), $lastWidth));
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

    /** @param  list<Constraint>  $widths */
    private function lastColumnWidth(string $pane, array $widths): int
    {
        $area = $this->drawn[$pane]['area'] ?? null;
        if ($area === null || $widths === []) {
            return 0;
        }
        $constraints = [Constraint::length(2)];
        foreach ($widths as $width) {
            $constraints[] = $width;
            $constraints[] = Constraint::length(1);
        }
        array_pop($constraints);
        $chunks = Layout::default()->direction(Direction::Horizontal)->constraints($constraints)->split(Area::fromDimensions(max(1, $area->width - 2), 1));

        return $chunks->get(count($constraints) - 1)->width;
    }

    private function alignLast(TableRow $row, int $width): TableRow
    {
        $cells = [];
        for ($i = 0; ($cell = $row->getCell($i)) !== null; $i++) {
            $cells[] = $cell;
        }
        if ($cells === [] || $width <= 0) {
            return $row;
        }
        // One cell of air stays between the text and the border.
        $last = array_pop($cells);
        $text = implode('', array_map(fn (Line $line): string => implode('', array_map(fn (Span $span): string => $span->content, iterator_to_array($line))), $last->content->lines));
        $room = $width - 1;
        $aligned = TableCell::fromString(mb_strlen($text) >= $room ? $text : str_repeat(' ', $room - mb_strlen($text)).$text.' ');
        $aligned->style = $last->style;

        return TableRow::fromCells(...[...$cells, $aligned]);
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
            $services = $gateway ? ['orbit-dns' => null, 'wg-easy' => null] : ['postgres' => 'PostgreSQL 16', 'mysql' => 'MySQL 8', 'redis' => 'Redis 7'];
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
                'slug' => 'charlie-shop', 'driver' => 'pgsql', 'version' => '16.4', 'node' => 'app-dev', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'charlie_shop', 'username' => 'charlie_shop', 'process' => 'postgres on app-dev',
                'targets' => [1, 2, 3],
                'stats' => [23, 100, 4, 18, 1, 99.2, '214 MB', '1.2 GB', 'none'],
                'users' => [
                    ['username' => 'charlie_shop', 'privileges' => 'owner', 'used_by' => 'charlie-shop/dev, staging', 'created' => '2026-08-02'],
                    ['username' => 'charlie_shop_ro', 'privileges' => 'read-only', 'used_by' => 'metrics exporter', 'created' => '2026-09-01'],
                    ['username' => 'nick', 'privileges' => 'superuser', 'used_by' => '—', 'created' => '2026-07-14'],
                ],
                'tables' => [['schema' => 'public', 'name' => 'users', 'rows' => '12 480', 'size' => '9.1 MB'], ['schema' => 'public', 'name' => 'orders', 'rows' => '88 102', 'size' => '61 MB'], ['schema' => 'public', 'name' => 'order_items', 'rows' => '301 774', 'size' => '140 MB'], ['schema' => 'audit', 'name' => 'events', 'rows' => '1 204 511', 'size' => '388 MB']],
            ],
            [
                'slug' => 'acme', 'driver' => 'mysql', 'version' => '8.4', 'node' => 'app-dev', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'acme', 'username' => 'acme', 'process' => 'mysql on app-dev',
                'targets' => [4, 5],
                'stats' => [12, 3, 4, 99.8, '1.9 GB', 'on'],
                'users' => [
                    ['username' => 'acme', 'host' => '%', 'privileges' => 'ALL on acme.*', 'used_by' => 'acme/dev, main'],
                    ['username' => 'acme_backup', 'host' => 'localhost', 'privileges' => 'SELECT, LOCK TABLES', 'used_by' => 'backup schedule'],
                ],
                'tables' => [['name' => 'users', 'engine' => 'InnoDB', 'rows' => '2 310', 'size' => '1.8 MB'], ['name' => 'invoices', 'engine' => 'InnoDB', 'rows' => '9 904', 'size' => '12 MB'], ['name' => 'sessions', 'engine' => 'MEMORY', 'rows' => '77', 'size' => '256 kB']],
            ],
            [
                'slug' => 'charlie-shop-cache', 'driver' => 'redis', 'version' => '7.4', 'node' => 'app-dev', 'host' => '127.0.0.1', 'port' => 6379, 'database' => '0', 'username' => 'charlie_shop', 'process' => 'redis on app-dev',
                'targets' => [1, 2, 3],
                'stats' => ['212 MB', '1 GB', 14, '1 240', 97.4, 0, 'AOF every second'],
                'keyspace' => [['db' => 'db0', 'keys' => '48 211', 'expires' => '39 870', 'avg_ttl' => '2h 14m'], ['db' => 'db1', 'keys' => '312', 'expires' => '0', 'avg_ttl' => '—']],
                'users' => [
                    ['username' => 'default', 'privileges' => 'on ~* +@all', 'used_by' => '—'],
                    ['username' => 'charlie_shop', 'privileges' => 'on ~charlie:* +@read +@write -@dangerous', 'used_by' => 'charlie-shop/dev, staging'],
                ],
                'slowlog' => [['at' => date('H:i:s', time() - 400), 'duration' => '31 ms', 'command' => 'KEYS charlie:cart:*'], ['at' => date('H:i:s', time() - 1900), 'duration' => '18 ms', 'command' => 'SMEMBERS charlie:sessions']],
            ],
            [
                'slug' => 'bravo-docs', 'driver' => 'sqlite', 'node' => 'gateway', 'path' => '/srv/orbit/apps/bravo-docs/main/database.sqlite', 'size' => '2.2 MB', 'journal' => 'WAL', 'page_size' => '4096',
                'targets' => [6],
                'tables' => [['name' => 'pages', 'rows' => '412', 'size' => '2.1 MB'], ['name' => 'revisions', 'rows' => '1 980', 'size' => '96 kB']],
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
