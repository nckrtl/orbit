<?php

declare(strict_types=1);

namespace App\Support\Tui;

use App\Support\Tui\Prompts\PanelMultiSelectPrompt;
use Closure;
use Laravel\Prompts\Key;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentRequest;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Requests\Schedules\ScheduleLogsRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseTablesResponse;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentEvent;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Processes\ProcessLogsResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Display\Area;

/**
 * Keyboard and mouse handling for `orbit top`: navigation, the actions menu, and the node
 * create form. Ported from the `design:top` sketch's `handleChar()`/`handleKey()`/
 * `handleMouse()`; State and UiState carry what the sketch kept on the command itself.
 */
final readonly class Interaction
{
    /** @param Closure(object, string): object $send Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(
        private State $state,
        private UiState $ui,
        private ActionRunner $actions,
        private Closure $send,
    ) {}

    public function handleChar(string $char): void
    {
        if ($this->ui->menu !== null) {
            if ($this->ui->menu['confirm'] !== null) {
                $this->finishConfirmation($this->ui->menu['confirm']->press($char));
            }

            return;
        }

        if ($this->ui->form !== null) {
            $this->ui->form->press($char);

            return;
        }

        $sections = array_keys(UiState::SECTIONS);

        match (true) {
            $char === 'r' => null, // A manual refresh is not modeled; the render loop already polls or applies events.
            $char === 'a' => $this->openMenu(),
            $char === 'c' && $this->ui->section === 'nodes' && $this->ui->pages === [] => $this->openForm(),
            $char === 'n' && $this->ui->hasFilters() => $this->cycleFilter('node'),
            $char === 'p' && $this->ui->hasFilters() => $this->cycleFilter('project'),
            ctype_digit($char) && isset($sections[(int) $char - 1]) => $this->ui->goTo($sections[(int) $char - 1]),
            default => null,
        };
    }

    public function handleKey(KeyCode $code): void
    {
        if ($this->ui->menu !== null) {
            $this->handleMenuKey($code);

            return;
        }

        if ($this->ui->form !== null) {
            $this->keyInForm($code);

            return;
        }

        if ($this->ui->focus === null) {
            $this->hoverKey($code);

            return;
        }

        match ($code) {
            KeyCode::Down => $this->move(1),
            KeyCode::Up => $this->move(-1),
            KeyCode::Enter => $this->openSelected(),
            KeyCode::Esc => $this->ui->focus = null,
            default => null,
        };
    }

    private function handleMenuKey(KeyCode $code): void
    {
        $menu = $this->ui->menu;

        if ($menu === null) {
            return;
        }

        if ($menu['confirm'] !== null) {
            $this->finishConfirmation($menu['confirm']->press(match ($code) {
                KeyCode::Enter => Key::ENTER, KeyCode::Esc => Key::ESCAPE,
                KeyCode::Left => Key::LEFT, KeyCode::Right => Key::RIGHT,
                KeyCode::Up => Key::UP, KeyCode::Down => Key::DOWN, KeyCode::Tab => Key::TAB,
                default => '',
            }));

            return;
        }

        $count = count($menu['actions']);

        match ($code) {
            KeyCode::Down => $this->ui->menu['selected'] = min($count - 1, $menu['selected'] + 1),
            KeyCode::Up => $this->ui->menu['selected'] = max(0, $menu['selected'] - 1),
            KeyCode::Enter => $this->chooseAction(),
            KeyCode::Esc => $this->ui->menu = null,
            default => null,
        };
    }

    /** Hovering: the sidebar reacts straight away, the page panes wait for Enter. */
    private function hoverKey(KeyCode $code): void
    {
        if ($this->ui->hover === 'nav') {
            $sections = array_keys(UiState::SECTIONS);
            $index = (int) array_search($this->ui->section, $sections, true);

            match ($code) {
                KeyCode::Down => $this->ui->goTo($sections[min(count($sections) - 1, $index + 1)]),
                KeyCode::Up => $this->ui->goTo($sections[max(0, $index - 1)]),
                KeyCode::Right, KeyCode::Enter => $this->ui->hover = $this->ui->paneOrder[0] ?? 'nav',
                KeyCode::Esc => $this->ui->back(),
                default => null,
            };

            return;
        }

        $index = (int) array_search($this->ui->hover, $this->ui->paneOrder, true);

        match ($code) {
            KeyCode::Left => $this->ui->hover = 'nav',
            KeyCode::Down => $this->ui->hover = $this->ui->paneOrder[min(count($this->ui->paneOrder) - 1, $index + 1)] ?? 'nav',
            KeyCode::Up => $this->ui->hover = $this->ui->paneOrder[max(0, $index - 1)] ?? 'nav',
            KeyCode::Enter => $this->focusPane($this->ui->hover),
            KeyCode::Esc => $this->ui->back(),
            default => null,
        };
    }

    private function focusPane(string $pane): void
    {
        $this->ui->focus = $pane;
        $this->maybeLazyLoad($pane);
    }

    public function handleMouse(MouseEvent $event): void
    {
        $x = $event->column;
        $y = $event->row;

        if ($this->ui->menu !== null) {
            if ($event->kind !== MouseEventKind::Down || $event->button !== MouseButton::Left) {
                return;
            }

            if ($this->ui->menu['confirm'] !== null) {
                $this->finishConfirmation($this->ui->menu['confirm']->click($x, $y));

                return;
            }

            $item = $this->hitRow('menu', $x, $y);

            if ($item !== null && $item < count($this->ui->menu['actions'])) {
                $this->ui->menu['selected'] = $item;
                $this->chooseAction();
            } else {
                $this->ui->menu = null;
            }

            return;
        }

        if ($this->ui->form !== null) {
            if ($event->kind !== MouseEventKind::Down || $event->button !== MouseButton::Left) {
                return;
            }

            if ($this->hitRow('back', $x, $y) !== null) {
                $this->ui->form = null;
            } elseif ($this->hitPoint('form:submit', $x, $y)) {
                $this->ui->form->focusField(count($this->ui->form->prompts));
                $this->submitForm();
            } else {
                foreach (array_keys($this->ui->form->prompts) as $index) {
                    if ($this->hitPoint("field:{$index}", $x, $y)) {
                        $this->ui->form->focusField($index);
                    }
                }
            }

            return;
        }

        if ($event->kind === MouseEventKind::ScrollDown || $event->kind === MouseEventKind::ScrollUp) {
            $pane = $this->paneAt($x, $y);

            if ($pane !== null && $pane !== 'nav') {
                $this->ui->focus = $pane;
                $this->move($event->kind === MouseEventKind::ScrollDown ? 1 : -1);
            }

            return;
        }

        if ($event->kind !== MouseEventKind::Down) {
            return;
        }

        if ($event->button === MouseButton::Left) {
            if ($this->hitRow('back', $x, $y) !== null) {
                $this->ui->back();

                return;
            }

            if ($this->hitPoint('create', $x, $y)) {
                $this->openForm();

                return;
            }

            foreach (['node', 'project'] as $filter) {
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
            $sections = array_keys(UiState::SECTIONS);

            if ($row !== null && isset($sections[$row])) {
                $this->ui->goTo($sections[$row]);
            }

            return;
        }

        // A click lands on a pane and, when it hits a row, selects that row; a click on the
        // already-selected row opens it. A right click lists the row's actions.
        $this->ui->hover = $pane;
        $this->ui->focus = $pane;
        $this->maybeLazyLoad($pane);
        $row = $this->hitRow($pane, $x, $y);

        if ($event->button === MouseButton::Right) {
            if ($row !== null) {
                $this->ui->selected[$pane] = $row;
                $this->openMenu([$x, $y]);
            }

            return;
        }

        if ($row !== null && $event->button === MouseButton::Left) {
            if (($this->ui->selected[$pane] ?? 0) === $row) {
                $this->openSelected();
            } else {
                $this->ui->selected[$pane] = $row;
            }
        }
    }

    private function paneAt(int $x, int $y): ?string
    {
        foreach ($this->ui->drawn as $name => $drawn) {
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
        return isset($this->ui->drawn[$name]) && $this->inside($this->ui->drawn[$name]['area'], $x, $y);
    }

    /** The row index under a point inside a drawn pane: below its border and header, above its bottom border. */
    private function hitRow(string $name, int $x, int $y): ?int
    {
        $drawn = $this->ui->drawn[$name] ?? null;

        if ($drawn === null || $x <= $drawn['area']->left() || $x >= $drawn['area']->right() - 1) {
            return null;
        }

        $first = $drawn['area']->top() + 1 + ($drawn['header'] ? 1 : 0);

        if ($y < $first || $y >= $drawn['area']->bottom() - 1) {
            return null;
        }

        $index = $y - $first + ($drawn['table']->offset ?? 0);

        return isset($drawn['ids']) && ! isset($drawn['ids'][$index]) ? null : $index;
    }

    private function linkAt(int $x, int $y): ?string
    {
        foreach ($this->ui->drawn as $name => $drawn) {
            if (str_starts_with($name, 'link:') && $this->inside($drawn['area'], $x, $y)) {
                return $name;
            }
        }

        return null;
    }

    // ---- navigation ---------------------------------------------------------------------------

    private function move(int $step): void
    {
        if ($this->ui->focus === null) {
            return;
        }

        $rows = count($this->ui->drawn[$this->ui->focus]['ids'] ?? []);
        $this->ui->selected[$this->ui->focus] = max(0, min(max(0, $rows - 1), ($this->ui->selected[$this->ui->focus] ?? 0) + $step));
    }

    private function openSelected(): void
    {
        if ($this->ui->focus === null) {
            return;
        }

        $record = $this->selectedRecord($this->ui->focus);

        if ($record !== null) {
            $this->openRecord($record['kind'], $record['row']);
        }
    }

    /** @param array<string, mixed> $row */
    private function openRecord(string $kind, array $row): void
    {
        $this->ui->open($kind, $row);
        $this->maybeLazyLoad('list', $kind, $row);
    }

    /** A node or app link on a page opens that record's page. */
    private function follow(string $link): void
    {
        $row = $this->ui->page()['row'] ?? [];

        if ($link === 'link:node') {
            $nodeId = $row['node']['id'] ?? $row['node_id'] ?? null;
            $node = $nodeId === null ? null : $this->state->nodeById($nodeId);

            if ($node !== null) {
                $this->openRecord('nodes', $node);
            }
        }

        if ($link === 'link:project') {
            $app = $this->state->appBySlug($row['app']['slug'] ?? '');

            if ($app !== null) {
                $this->openRecord('apps', $app);
            }
        }
    }

    private function cycleFilter(string $filter): void
    {
        $values = $filter === 'node' ? array_column($this->state->nodes, 'name') : array_column($this->state->apps, 'slug');
        $current = $this->ui->filters[$filter];
        $index = $current === null ? -1 : (int) array_search($current, $values, true);
        $this->ui->filters[$filter] = $values[$index + 1] ?? null;
        $this->ui->selected['list'] = 0;
    }

    /** @return array{kind: string, row: array<string, mixed>}|null */
    private function selectedRecord(string $pane): ?array
    {
        $drawn = $this->ui->drawn[$pane] ?? [];
        $index = $this->ui->selected[$pane] ?? 0;
        $id = $drawn['ids'][$index] ?? null;
        $kind = $drawn['families'][$index] ?? $drawn['kind'] ?? '';

        if ($id === null || ! isset($drawn['table'], $drawn['area']) || in_array($kind, ['deploysteps', 'tables', 'users'], true)) {
            return null;
        }

        $offset = $drawn['table']->offset;
        $visibleRows = max(0, $drawn['area']->height - 2 - ($drawn['header'] ? 1 : 0));

        if ($index < $offset || $index >= $offset + $visibleRows) {
            return null;
        }

        $row = $this->state->recordById($kind, $id);

        if ($row === null) {
            $this->ui->message = 'The selected record disappeared. Select a current row.';

            return null;
        }

        return ['kind' => $kind, 'row' => $row];
    }

    /**
     * Fetches Tables when a database record opens, and a log when a process or schedule
     * record opens.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function maybeLazyLoad(string $pane, ?string $kind = null, ?array $row = null): void
    {
        $kind ??= $this->ui->page()['kind'] ?? null;
        $row ??= $this->ui->page()['row'] ?? null;

        if ($row === null) {
            return;
        }

        try {
            if ($kind === 'databases' && ! isset($this->state->databaseTables[$row['slug']])) {
                $response = ($this->send)(new ListDatabaseTablesRequest($row['slug']), DatabaseTablesResponse::class);
                assert($response instanceof DatabaseTablesResponse);
                $this->state->databaseTables[$row['slug']] = $response->tables;
            }

            if ($kind === 'processes' && ! isset($this->state->processLogs[$row['id']])) {
                $response = ($this->send)(new ProcessLogsRequest($row['id']), ProcessLogsResponse::class);
                assert($response instanceof ProcessLogsResponse);
                $this->state->processLogs[$row['id']] = $response->logs === '' ? [] : explode("\n", rtrim($response->logs, "\n"));
            }

            if ($kind === 'schedules' && ! isset($this->state->scheduleLogs[$row['id']])) {
                $response = ($this->send)(new ScheduleLogsRequest($row['id']), ScheduleLogsResponse::class);
                assert($response instanceof ScheduleLogsResponse);
                $this->state->scheduleLogs[$row['id']] = $response->output === '' ? [] : explode("\n", rtrim($response->output, "\n"));
            }

            if ($kind === 'deployments' && ! isset($this->state->deploymentLogs[$row['id']])) {
                $response = ($this->send)(new ShowAppInstanceDeploymentRequest($row['id']), AppInstanceDeploymentResponse::class);
                assert($response instanceof AppInstanceDeploymentResponse);
                $this->state->deploymentLogs[$row['id']] = self::deploymentLogLines($response->events ?? []);
            }
        } catch (GatewayApiException $exception) {
            $this->ui->message = $exception->getMessage();
        }
    }

    /**
     * Phase markers and stdout/stderr output lines from a deployment's recorded events, in the
     * order the deployment produced them. Mirrors instance:deployment:show's own log rendering.
     *
     * @param  list<AppInstanceDeploymentEvent>  $events
     * @return list<string>
     */
    private static function deploymentLogLines(array $events): array
    {
        $lines = [];

        foreach ($events as $event) {
            if ($event->type === 'phase') {
                $phase = str_replace('_', ' ', (string) $event->phase);
                $lines[] = $event->stepName !== null ? "== {$phase}: {$event->stepName} ==" : "== {$phase} ==";

                continue;
            }

            if ($event->type === 'output' && $event->value !== null) {
                foreach (explode("\n", rtrim($event->value, "\n")) as $line) {
                    $lines[] = "{$event->stream}: {$line}";
                }

                continue;
            }

            if ($event->type === 'output_truncated') {
                $lines[] = '[output truncated]';
            }
        }

        return $lines;
    }

    // ---- actions menu ---------------------------------------------------------------------------

    /** @param array{int, int}|null $at */
    private function openMenu(?array $at = null): void
    {
        $page = $this->ui->page();
        $pane = $this->ui->focus;

        if ($pane !== null && $pane !== 'nav') {
            $record = $this->selectedRecord($pane);
            $kind = $record['kind'] ?? '';
            $row = $record['row'] ?? null;
        } elseif ($page !== null) {
            $kind = $page['kind'];
            $row = $page['row'];
        } else {
            return;
        }

        if ($row === null) {
            return;
        }

        if ($kind === 'firewall') {
            $row['node'] = $this->state->nodeName($row['node_id']);
        }

        $actions = $this->actions->actionsFor($kind, $row);

        if ($actions === []) {
            return;
        }

        $this->ui->menu = ['kind' => $kind, 'title' => $this->rowTitleFor($kind, $row), 'row' => $row, 'actions' => $actions, 'selected' => 0, 'confirm' => null, 'at' => $at];
    }

    /** @param array<string, mixed> $row */
    private function rowTitleFor(string $kind, array $row): string
    {
        return match ($kind) {
            'nodes' => $row['name'],
            'apps' => $row['slug'],
            'instances' => "{$row['app']['slug']}/{$row['name']}",
            'processes', 'schedules' => $row['name'],
            'databases' => $row['slug'],
            'firewall' => $row['name'],
            default => '',
        };
    }

    /** Enter/click on a menu item: a destructive action asks to confirm first. */
    private function chooseAction(): void
    {
        $menu = $this->ui->menu;

        if ($menu === null) {
            return;
        }

        $label = array_keys($menu['actions'])[$menu['selected']];
        $action = $menu['actions'][$label];

        if (! $action->real) {
            $this->ui->message = $action->command;
            $this->ui->menu = null;

            return;
        }

        if ($action->destructive) {
            $this->ui->menu['confirm'] = new Confirmation($action->description);

            return;
        }

        $this->runAction();
    }

    private function finishConfirmation(?bool $answer): void
    {
        if ($answer === true) {
            $this->runAction();
        } elseif ($answer === false) {
            $this->ui->menu = null;
        }
    }

    /** @param array<string, mixed> $row The frozen record whose destructive question was shown. */
    private function destructiveTargetIsCurrent(string $kind, array $row): bool
    {
        $keys = match ($kind) {
            'databases' => ['slug'],
            'firewall' => ['node_id', 'name'],
            default => [],
        };

        if ($keys === []) {
            return false;
        }

        foreach ($this->state->{$kind} as $current) {
            if ($current['id'] !== $row['id']) {
                continue;
            }

            return array_all($keys, static fn (string $key): bool => $current[$key] === $row[$key]);
        }

        return false;
    }

    /** Runs the selected menu action's real request, or confirms a destructive one. */
    private function runAction(): void
    {
        $menu = $this->ui->menu;

        if ($menu === null) {
            return;
        }

        $label = array_keys($menu['actions'])[$menu['selected']];
        $row = $menu['row'];
        $this->ui->menu = null;

        if ($menu['actions'][$label]->destructive && ! $this->destructiveTargetIsCurrent($menu['kind'], $row)) {
            $this->ui->message = 'The selected record changed or disappeared. Reopen its actions and confirm the current target.';

            return;
        }

        try {
            $result = $this->actions->run($menu['kind'], $label, $row);
            $this->ui->message = $result['message'];

            if ($result['row'] !== null) {
                $this->state->updateRow($menu['kind'], $result['row']);

                foreach ($this->ui->pages as $index => $page) {
                    if ($page['kind'] === $menu['kind'] && ($page['row']['id'] ?? null) === ($row['id'] ?? null)) {
                        $this->ui->pages[$index]['row'] = $result['row'];
                    }
                }
            }
        } catch (GatewayApiException $exception) {
            $this->ui->message = $exception->getMessage();
        }

    }

    // ---- node create form -----------------------------------------------------------------------

    private function openForm(): void
    {
        $this->ui->form = new NodeFormState;
        $this->ui->focus = null;
    }

    private function keyInForm(KeyCode $code): void
    {
        $form = $this->ui->form;

        if ($form === null) {
            return;
        }

        if ($code === KeyCode::Esc) {
            $this->ui->form = null;

            return;
        }

        if ($form->onButton()) {
            match ($code) {
                KeyCode::Enter => $this->submitForm(),
                KeyCode::Up, KeyCode::BackTab => $form->focusField($form->active - 1),
                default => null,
            };

            return;
        }

        $select = $form->prompts[$form->active][1] instanceof PanelMultiSelectPrompt;
        $step = match ($code) {
            KeyCode::Tab => 1,
            KeyCode::BackTab => -1,
            KeyCode::Down => $select ? 0 : 1,
            KeyCode::Up => $select ? 0 : -1,
            default => 0,
        };

        if ($step !== 0) {
            $form->focusField($form->active + $step);

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
            $form->press($key);
        }
    }

    private function submitForm(): void
    {
        $form = $this->ui->form;

        if ($form === null || ! $form->validate()) {
            return;
        }

        $values = $form->values();
        $this->ui->creatingNode = true;

        try {
            $node = ($this->send)(
                new AddNodeRequest(
                    name: $values['name'],
                    publicSshHost: $values['host'] === '' ? null : $values['host'],
                    roles: $values['roles'],
                    publicSshPort: (int) $values['port'],
                    user: $values['user'],
                    tld: $values['tld'] === '' ? null : $values['tld'],
                ),
                NodeResponse::class,
            );
            assert($node instanceof NodeResponse);
            $this->state->nodes[] = State::nodeRow($node);
            $this->ui->form = null;
            $this->ui->creatingNode = false;
            $this->ui->message = "Node [{$node->name}] is {$node->status}.";
            $this->openRecord('nodes', State::nodeRow($node));
        } catch (GatewayApiException $exception) {
            $this->ui->creatingNode = false;
            $form->error = $exception->getMessage();
        }
    }
}
