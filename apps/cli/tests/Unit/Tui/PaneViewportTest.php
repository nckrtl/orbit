<?php

declare(strict_types=1);

use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\UiState;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->state = tui_test_state();
    $process = $this->state->processes[0];
    $this->state->processes = [];
    foreach (range(1, 40) as $id) {
        $this->state->processes[] = [...$process, 'id' => $id, 'name' => sprintf('worker-%02d', $id)];
        $this->state->processLogs[$id] = [];
    }
    $this->state->scheduleLogs[$this->state->schedules[0]['id']] = [];
    $this->ui = new UiState;
    $this->sent = [];
    $send = function (object $request): object {
        $this->sent[] = $request->resolveEndpoint();
        preg_match('~/processes/(\d+)/~', $request->resolveEndpoint(), $matches);
        $row = $this->state->recordById('processes', (int) $matches[1]);

        return ProcessResponse::fromGatewayData($row, '0198e15d-16c4-7855-8eb2-182b53ad28ba');
    };
    $this->interaction = new Interaction($this->state, $this->ui, new ActionRunner($send), $send);
});

describe('last-drawn pane authority', function (): void {
    it('retains the renderer offset across keyboard wheel and resized frames', function (): void {
        $this->ui->goTo('processes');
        $this->ui->focus = 'list';
        render_top_screen($this->ui, $this->state, columns: 100, rows: 15);
        foreach (range(1, 30) as $_) {
            $this->interaction->handleKey(KeyCode::Down);
            render_top_screen($this->ui, $this->state, columns: 100, rows: 15);
        }
        $offset = $this->ui->drawn['list']['table']->offset;
        $this->interaction->handleKey(KeyCode::Up);
        render_top_screen($this->ui, $this->state, columns: 100, rows: 15);
        expect($this->ui->drawn['list']['table']->offset)->toBe($offset)
            ->and($this->ui->selected['list'])->toBe(29);
        $area = $this->ui->drawn['list']['area'];
        $this->interaction->handleMouse(MouseEvent::new(MouseEventKind::ScrollDown, MouseButton::Left, $area->left() + 3, $area->top() + 2, 0));
        render_top_screen($this->ui, $this->state, columns: 100, rows: 12);
        expect($this->ui->selected['list'])->toBe(30)
            ->and($this->ui->drawn['list']['table']->offset)->toBeGreaterThan($offset);

        $this->state->processes = array_slice($this->state->processes, 0, 2);
        render_top_screen($this->ui, $this->state, columns: 100, rows: 20);
        expect($this->ui->selected['list'])->toBe(1)
            ->and($this->ui->drawn['list']['table']->selected)->toBe(1);
        $this->interaction->handleChar('a');
        expect($this->ui->menu['row']['id'])->toBe(2);
    });

    it('keeps mixed attention families distinct when IDs collide', function (): void {
        $this->state->nodes[0]['status'] = 'failed';
        $this->state->processes[0]['runtime_status'] = 'failed';
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = 'attention';
        $this->interaction->handleKey(KeyCode::Down);
        $this->interaction->handleChar('a');
        expect($this->ui->menu['kind'])->toBe('processes')
            ->and($this->ui->menu['row']['id'])->toBe(1);
    });

    it('retains leaf pane movement without opening records or actions', function (string $pane): void {
        if ($pane === 'deploysteps') {
            $this->state->instances[0]['deploy_steps'] = [
                ['id' => 1, 'phase' => 'prepare', 'name' => 'first', 'timeout_seconds' => 60],
                ['id' => 2, 'phase' => 'prepare', 'name' => 'second', 'timeout_seconds' => 60],
            ];
            $this->ui->open('instances', $this->state->instances[0]);
        } else {
            $this->state->databaseTables['charlie-shop'] = ['orders', 'users'];
            $this->state->setDatabaseUsers('charlie-shop', [
                ['username' => 'first', 'privileges' => 'read', 'created_by' => 'gateway'],
                ['username' => 'second', 'privileges' => 'read', 'created_by' => 'gateway'],
            ]);
            $this->ui->open('databases', $this->state->databases[0]);
        }
        $this->state->processes = [];
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = $pane;
        $this->interaction->handleKey(KeyCode::Down);
        $this->interaction->handleKey(KeyCode::Enter);
        $this->interaction->handleChar('a');
        expect($this->ui->selected[$pane])->toBe(1)
            ->and($this->ui->pages)->toHaveCount(1)
            ->and($this->ui->menu)->toBeNull()->and($this->sent)->toBe([]);
    })->with(['deploysteps', 'tables', 'users']);

    it('opens the drawn Deployment from the current historical cache by ID', function (): void {
        $this->state->processes = [];
        $deployment = [
            'id' => 12, 'started' => 'today', 'release' => 'first', 'branch' => 'main',
            'commit' => 'aaaaaaa', 'by' => 'gateway', 'duration' => '1s', 'status' => 'succeeded',
        ];
        $this->state->setDeployments(1, [$deployment]);
        $this->state->deploymentLogs[12] = [];
        $this->ui->open('instances', $this->state->instances[0]);
        render_top_screen($this->ui, $this->state);
        $this->state->setDeployments(1, [[...$deployment, 'id' => 13], [...$deployment, 'release' => 'updated']]);
        $this->ui->focus = 'deployments';
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->ui->page()['kind'])->toBe('deployments')
            ->and($this->ui->page()['row']['id'])->toBe(12)
            ->and($this->ui->page()['row']['release'])->toBe('updated');
    });

    it('opens each Dashboard family from the rows it drew', function (string $pane): void {
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = $pane;
        $this->interaction->handleKey(KeyCode::Enter);

        expect($this->ui->page()['kind'] ?? null)->toBe($pane)
            ->and($this->ui->page()['row']['id'] ?? null)->toBe($this->state->{$pane}[0]['id']);
    })->with(['apps', 'instances', 'processes', 'schedules']);

    it('uses the renderer offset for the first and last visible row', function (string $edge, string $button): void {
        $this->ui->goTo('processes');
        $this->ui->focus = 'list';
        $this->ui->selected['list'] = 30;
        $screen = render_top_screen($this->ui, $this->state, columns: 100, rows: 15);
        $drawn = $this->ui->drawn['list'];
        $area = $drawn['area'];
        $offset = $drawn['table']->offset;
        $index = $edge === 'first' ? $offset : $offset + $area->height - 4;
        $y = $edge === 'first' ? $area->top() + 2 : $area->bottom() - 2;
        $event = MouseEvent::new(MouseEventKind::Down, $button === 'right' ? MouseButton::Right : MouseButton::Left, $area->left() + 3, $y, 0);

        expect($offset)->toBeGreaterThan(0)->and($screen)->toContain(sprintf('worker-%02d', $index + 1));
        $this->interaction->handleMouse($event);
        if ($button === 'left' && $this->ui->page() === null) {
            $this->interaction->handleMouse($event);
        }
        expect($button === 'right' ? $this->ui->menu['row']['id'] : $this->ui->page()['row']['id'])->toBe($index + 1);
        if ($button === 'right') {
            $this->interaction->handleKey(KeyCode::Enter);
            expect($this->sent)->toBe(['/api/v1/processes/'.($index + 1).'/restart']);
        }
    })->with(['first', 'last'])->with(['left', 'right']);

    it('resolves the drawn ID after reordering instead of a current index', function (): void {
        $this->ui->goTo('processes');
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = 'list';
        $this->state->processes = array_reverse($this->state->processes);
        $this->interaction->handleChar('a');
        expect($this->ui->menu['row']['id'])->toBe(1);
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe(['/api/v1/processes/1/restart']);
    });

    it('refuses a removed drawn identity', function (string $input): void {
        $this->ui->goTo('processes');
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = 'list';
        array_shift($this->state->processes);
        if ($input === 'enter') {
            $this->interaction->handleKey(KeyCode::Enter);
        } else {
            $this->interaction->handleChar('a');
        }
        expect($this->ui->menu)->toBeNull()->and($this->ui->page())->toBeNull()->and($this->sent)->toBe([]);
    })->with(['enter', 'actions']);

    it('does not open actions on borders headers or blank space', function (string $point): void {
        $this->ui->goTo('nodes');
        render_top_screen($this->ui, $this->state);
        $area = $this->ui->drawn['list']['area'];
        [$x, $y] = match ($point) {
            'left' => [$area->left(), $area->top() + 2],
            'right' => [$area->right() - 1, $area->top() + 2],
            'header' => [$area->left() + 2, $area->top() + 1],
            'blank' => [$area->left() + 2, $area->top() + 5],
        };
        $this->interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Right, $x, $y, 0));
        expect($this->ui->menu)->toBeNull()->and($this->sent)->toBe([]);
    })->with(['left', 'right', 'header', 'blank']);
});
