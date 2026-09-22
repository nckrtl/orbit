<?php

declare(strict_types=1);

use App\Commands\TopCommand;
use App\Support\Realtime\RealtimeEvent;
use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\UiState;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseTablesResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->state = tui_test_state();
    $this->ui = new UiState;
    $this->sent = [];
    $send = function (object $request, string $class): object {
        $this->sent[] = $request->resolveEndpoint();

        return match ($class) {
            ProcessResponse::class => ProcessResponse::fromGatewayData([
                ...$this->state->recordById('processes', 1), 'runtime_status' => 'inactive',
            ], '0198e15d-16c4-7855-8eb2-182b53ad28ba'),
            DatabaseTablesResponse::class => new DatabaseTablesResponse('current-db', 'pgsql', ['current_table'], 'r'),
            default => throw new RuntimeException('Unexpected request.'),
        };
    };
    $this->interaction = new Interaction($this->state, $this->ui, new ActionRunner($send), $send);
});

describe('canonical open records', function (): void {
    it('shows unavailable recovery at 80 columns without action geometry', function (): void {
        $this->ui->open('processes', $this->state->processes[0]);
        $this->state->processes = [];
        $screen = render_top_screen($this->ui, $this->state, columns: 80, rows: 24);
        expect_output($screen, 'top/record/unavailable.txt');
        expect(array_keys($this->ui->drawn))->toBe(['nav', 'back']);
    });

    it('returns from an unavailable page through its Back link', function (): void {
        $this->ui->open('nodes', $this->state->nodes[0]);
        $this->state->nodes = [];
        render_top_screen($this->ui, $this->state);
        $area = $this->ui->drawn['back']['area'];
        $this->interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Left, $area->left() + 3, $area->top(), 0));
        expect($this->ui->page())->toBeNull()->and($this->sent)->toBe([]);
    });

    it('stores only fleet identity and redraws current values', function (string $kind): void {
        $row = $this->state->{$kind}[0];
        $this->ui->open($kind, $row);
        $field = in_array($kind, ['apps', 'databases'], true) ? 'slug' : 'name';
        $this->state->updateRow($kind, [...$row, $field => 'current-record']);

        expect($this->ui->page())->toBe(['kind' => $kind, 'id' => $row['id']])
            ->and(render_top_screen($this->ui, $this->state, columns: 160))->toContain('current-record');
    })->with(['nodes', 'apps', 'instances', 'processes', 'schedules', 'databases', 'firewall']);

    it('keeps a missing page unavailable when another record reuses its name', function (string $kind): void {
        $row = $this->state->{$kind}[0];
        $this->ui->open($kind, $row);
        $this->state->{$kind} = [[...$row, 'id' => is_int($row['id']) ? 2 : 'replacement-id']];
        $screen = render_top_screen($this->ui, $this->state);
        $this->interaction->handleChar('a');
        $this->interaction->handleKey(KeyCode::Enter);

        expect($screen)->toContain('unavailable', 'This record is no longer available. Press Esc or ‹ back.')
            ->and($this->ui->page()['id'])->toBe($row['id'])
            ->and(array_keys($this->ui->drawn))->toBe(['nav', 'back'])
            ->and($this->ui->menu)->toBeNull()->and($this->sent)->toBe([]);

        $this->state->{$kind}[] = $row;
        expect(render_top_screen($this->ui, $this->state))->not->toContain('This record is no longer available.');
        $this->interaction->handleKey(KeyCode::Esc);
        expect($this->ui->page())->toBeNull();
    })->with(['nodes', 'apps', 'instances', 'processes', 'schedules', 'databases', 'firewall']);

    it('shows event updates and deletion after returning to a stacked page', function (): void {
        $this->ui->open('processes', $this->state->processes[0]);
        $this->ui->open('nodes', $this->state->nodes[0]);
        $this->state->applyEvent(RealtimeEvent::fromChannelPayload('event', [
            'type' => 'process.status', 'id' => 1, 'at' => '2026-09-22T12:00:00+00:00',
            'data' => ['id' => 1, 'runtime_status' => 'inactive'],
        ]));
        $this->ui->back();
        expect(render_top_screen($this->ui, $this->state))->toMatch('/Runtime status\s+inactive/');
        $this->ui->open('nodes', $this->state->nodes[0]);
        $this->state->applyEvent(RealtimeEvent::fromChannelPayload('event', [
            'type' => 'process.deleted', 'id' => 2, 'at' => '2026-09-22T12:00:01+00:00', 'data' => ['id' => 1],
        ]));
        $this->ui->back();
        expect(render_top_screen($this->ui, $this->state))->toContain('Process: unavailable');
    });

    it('resolves an open Process through queued polling and a full reload', function (): void {
        $row = $this->state->processes[0];
        $this->ui->open('processes', $row);
        $this->state->queueProcesses();
        expect(render_top_screen($this->ui, $this->state))->toContain('Process: unavailable');
        $this->interaction->handleChar('a');
        expect($this->ui->menu)->toBeNull()->and($this->sent)->toBe([]);
        $this->state->loadNextProcesses(fn (): ProcessesResponse => new ProcessesResponse([ProcessResponse::fromGatewayData([...$row, 'runtime_status' => 'inactive'], 'r')], 'r'));
        expect(render_top_screen($this->ui, $this->state))->toMatch('/Runtime status\s+inactive/');
        $this->ui->open('nodes', $this->state->nodes[0]);
        $this->state->load(fn (object $request, string $class): object => $class === SchedulesResponse::class ? SchedulesResponse::fromGatewayData([], 'r') : new $class([], 'r'));
        expect(render_top_screen($this->ui, $this->state))->toContain('Node: unavailable');
    });

    it('does not open a last-drawn child after its page owner disappears', function (): void {
        $this->ui->open('nodes', $this->state->nodes[0]);
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = 'instances';
        $this->state->nodes = [];
        $this->interaction->handleKey(KeyCode::Enter);
        $this->interaction->handleChar('a');
        expect($this->ui->pages)->toHaveCount(1)->and($this->ui->menu)->toBeNull()->and($this->sent)->toBe([]);
    });

    it('loads database tables using the current page selector', function (): void {
        $this->ui->open('databases', $this->state->databases[0]);
        $this->state->databases[0]['slug'] = 'current-db';
        render_top_screen($this->ui, $this->state);
        $this->interaction->handleKey(KeyCode::Right);
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe(['/api/v1/database-connections/current-db/tables']);
    });

    it('refreshes only the current visible record and never a missing page', function (string $kind): void {
        $this->ui->open($kind, $this->state->{$kind}[0]);
        if ($kind === 'databases') {
            $this->state->databases[0]['slug'] = 'current-db';
        }
        $method = new ReflectionMethod(TopCommand::class, 'visibleRefreshKeys');
        $expected = match ($kind) {
            'nodes' => [[1], false, [], []],
            'instances' => [[], false, [1], []],
            'databases' => [[], false, [], ['current-db']],
        };
        expect($method->invoke(new TopCommand, $this->ui, $this->state))->toBe($expected);
        $this->state->{$kind} = [];
        expect($method->invoke(new TopCommand, $this->ui, $this->state))->toBe([[], false, [], []]);
    })->with(['nodes', 'instances', 'databases']);

    it('keeps historical Deployment data explicit when its source cache expires', function (): void {
        $deployment = ['id' => 12, 'release' => '20260101000000', 'branch' => 'main', 'commit' => 'aaaaaaa',
            'started' => 'today', 'finished' => 'today', 'duration' => '1s', 'status' => 'succeeded',
            'failed_step' => null, 'error_code' => null, 'selected_release' => '20260101000000', 'by' => 'gateway'];
        $this->ui->open('deployments', $deployment);
        $this->state->setDeployments(1, null);
        $this->state->deploymentLogs[12] = ['Recorded historical log.'];
        expect($this->ui->page())->toBe(['kind' => 'deployments', 'id' => 12, 'historical' => $deployment])
            ->and(render_top_screen($this->ui, $this->state))->toContain('20260101000000', 'Recorded historical log.');
        $this->interaction->handleChar('a');
        expect($this->ui->menu)->toBeNull()->and($this->sent)->toBe([]);
    });
});

describe('canonical menu dispatch', function (): void {
    it('refuses an unchanged action label after Schedule or Instance ownership changes', function (string $change): void {
        $kind = str_starts_with($change, 'schedule') ? 'schedules' : 'instances';
        $this->ui->open($kind, $this->state->{$kind}[0]);
        $this->interaction->handleChar('a');
        match ($change) {
            'schedule-id' => $this->state->schedules[0]['target_id'] = 2,
            'schedule-type' => $this->state->schedules[0]['target_type'] = 'node',
            'instance-app' => $this->state->instances[0]['app']['id'] = 2,
            'instance-node' => $this->state->instances[0]['node']['id'] = 2,
        };
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull()
            ->and($this->ui->message)->toContain('changed or disappeared');
    })->with(['schedule-id', 'schedule-type', 'instance-app', 'instance-node']);

    it('shows the complete recovery message before footer key hints', function (): void {
        $this->ui->message = 'The record changed or disappeared. Reopen its actions.';
        $footer = new ReflectionMethod(TopCommand::class, 'footer')->invoke(new TopCommand, $this->ui);
        expect($footer)->toBe('  '.$this->ui->message)
            ->and(render_top_screen($this->ui, $this->state, footer: $footer, columns: 80, rows: 24))->toContain($this->ui->message);
    });

    it('does not dispatch a child menu after its open page owner disappears', function (): void {
        $this->ui->open('instances', $this->state->instances[0]);
        render_top_screen($this->ui, $this->state);
        $this->ui->focus = 'processes';
        $this->interaction->handleChar('a');
        expect($this->ui->menu)->not->toBeNull();
        $this->state->instances = [];
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull();
    });

    it('refuses a stale Stop choice without substituting Start at the same position', function (): void {
        $this->ui->open('processes', $this->state->processes[0]);
        $this->interaction->handleChar('a');
        $this->interaction->handleKey(KeyCode::Down);
        $this->state->processes[0]['runtime_status'] = 'inactive';
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull()
            ->and($this->ui->message)->toContain('changed or disappeared');
        $this->interaction->handleChar('a');
        expect(array_keys($this->ui->menu['actions']))->toBe(['restart', 'start']);
        $this->interaction->handleKey(KeyCode::Down);
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe(['/api/v1/processes/1/start']);
    });

    it('refuses a changed or missing Process target even when its action label is unchanged', function (string $change): void {
        $this->ui->open('processes', $this->state->processes[0]);
        $this->interaction->handleChar('a');
        match ($change) {
            'deleted' => $this->state->processes = [],
            'recreated' => $this->state->processes[0]['id'] = 2,
            'renamed' => $this->state->processes[0]['name'] = 'new-name',
            'target-id' => $this->state->processes[0]['target_id'] = 2,
            'target-type' => $this->state->processes[0]['target_type'] = 'node',
        };
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull()
            ->and($this->ui->message)->toContain('changed or disappeared');
    })->with(['deleted', 'recreated', 'renamed', 'target-id', 'target-type']);

    it('keeps a chosen ID after reordering and updates every stacked copy through State', function (): void {
        $this->ui->open('processes', $this->state->processes[0]);
        $this->ui->open('nodes', $this->state->nodes[0]);
        $this->ui->open('processes', $this->state->processes[0]);
        $this->interaction->handleChar('a');
        $this->interaction->handleKey(KeyCode::Down);
        array_unshift($this->state->processes, [...$this->state->processes[0], 'id' => 2]);
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe(['/api/v1/processes/1/stop'])
            ->and(render_top_screen($this->ui, $this->state))->toMatch('/Runtime status\s+inactive/');
        $this->ui->back();
        $this->ui->back();
        expect(render_top_screen($this->ui, $this->state))->toMatch('/Runtime status\s+inactive/');
    });

    it('refuses Schedule enable after an event makes it ineligible', function (): void {
        $this->state->schedules[0]['desired_timer_state'] = 'disabled';
        $this->ui->open('schedules', $this->state->schedules[0]);
        $this->interaction->handleChar('a');
        $this->interaction->handleKey(KeyCode::Down);
        $this->state->schedules[0]['desired_timer_state'] = 'enabled';
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe([])->and($this->ui->menu)->toBeNull();
    });

    it('refuses a stale terminal-command hint after its target changes', function (): void {
        $this->ui->open('nodes', $this->state->nodes[0]);
        $this->interaction->handleChar('a');
        $this->interaction->handleKey(KeyCode::Down);
        $this->state->nodes[0]['name'] = 'new-node';
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->sent)->toBe([])->and($this->ui->message)->not->toContain('orbit node:ssh beast');
        $this->interaction->handleChar('a');
        $this->interaction->handleKey(KeyCode::Down);
        $this->interaction->handleKey(KeyCode::Enter);
        expect($this->ui->message)->toBe('orbit node:ssh new-node');
    });
});
