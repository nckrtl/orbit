<?php

declare(strict_types=1);

use App\Support\Console\TerminalText;
use App\Support\Tui\ActionRunner;
use App\Support\Tui\Interaction;
use App\Support\Tui\TableColumns;
use App\Support\Tui\UiState;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseButton;
use PhpTui\Term\MouseEventKind;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableRenderer;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use Tests\TestCase;

uses(TestCase::class);

describe('finite TUI table columns', function (): void {
    it('uses positive finite columns for every full Unicode cell or refuses', function (): void {
        $headers = ['Name', 'Owner', 'CPU/MEM'];
        $values = ['worker-東京-é', 'charlie-shop/dev', '400%/12.34GB'];
        foreach (range(1, 140) as $width) {
            $columns = TableColumns::resolve($width, $headers, [TableRow::fromStrings(...$values)], [Constraint::percentage(40), Constraint::percentage(40), Constraint::min(12)]);
            if ($width < $columns->requiredWidth) {
                expect($columns->widths)->toBe([]);

                continue;
            }
            expect(array_sum($columns->widths) + 6)->toBe($width);
            $table = TableWidget::default()->widths(...$columns->constraints())->highlightSymbol('› ')->select(0);
            $table->columnSpacing = 1;
            $actual = (new ReflectionMethod(TableRenderer::class, 'getColumnsWidths'))->invoke(new TableRenderer, $table, $width - 2, 2);
            expect(array_column($actual, 1))->toBe($columns->widths);
            foreach ($actual as [$left, $size]) {
                expect($left)->toBeGreaterThanOrEqual(2)->and($left + $size)->toBeLessThanOrEqual($width - 2);
            }
            foreach ($columns->widths as $index => $resolved) {
                expect($resolved)->toBeGreaterThanOrEqual(max(1, TerminalText::width($values[$index]), TerminalText::width($headers[$index])));
                expect($columns->constraints()[$index]->length)->toBe($resolved);
            }
        }
    });

    it('renders populated and empty Dashboards at narrow sizes', function (int $columns, int $rows, bool $empty): void {
        $state = tui_test_state();
        if ($empty) {
            foreach (['nodes', 'apps', 'instances', 'processes', 'schedules', 'databases', 'firewall'] as $family) {
                $state->{$family} = [];
            }
        }
        $ui = new UiState;
        $screen = render_top_screen($ui, $state, columns: $columns, rows: $rows);
        expect($screen)->toContain($empty ? 'No processes.' : 'Needs');
        expect($ui->drawn['processes']['ids'] ?? [])->toBe([]);
        expect($ui->drawn['processes'])->not->toHaveKey('table');
    })->with([[80, 24], [80, 40], [70, 24]])->with([true, false]);

    it('refuses narrow Process tables without losing selection and restores them on resize', function (string $view): void {
        $state = tui_test_state();
        $ui = new UiState;
        if ($view === 'nodes') {
            $state->processes[0]['target_type'] = 'node';
            $state->processes[0]['target_id'] = $state->nodes[0]['id'];
        }
        if (in_array($view, ['nodes', 'instances'], true)) {
            $ui->open($view, $state->{$view}[0]);
        } else {
            $ui->goTo($view);
        }
        $pane = $view === 'processes' ? 'list' : 'processes';
        $ui->focus = $pane;
        $ui->selected[$pane] = 0;
        $send = fn (): never => throw new RuntimeException('A refused table must not send a request.');
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);
        render_top_screen($ui, $state, columns: 120, rows: 40);
        expect($ui->drawn[$pane])->toHaveKey('table');
        $screen = render_top_screen($ui, $state, columns: 70, rows: 24);
        expect($screen)->toContain('Needs')->and($ui->drawn[$pane]['ids'])->toBe([]);
        $pages = $ui->pages;
        $interaction->handleKey(KeyCode::Down);
        $interaction->handleKey(KeyCode::Enter);
        $interaction->handleChar('a');
        $area = $ui->drawn[$pane]['area'];
        $interaction->handleMouse(MouseEvent::new(MouseEventKind::Down, MouseButton::Right, $area->left() + 3, $area->top() + 2, 0));
        expect($ui->pages)->toBe($pages)->and($ui->menu)->toBeNull()->and($ui->selected[$pane])->toBe(0);
        $screen = render_top_screen($ui, $state, columns: 120, rows: 40);
        expect($screen)->toContain('horizon', '20%/1.23GB')->and($ui->drawn[$pane])->toHaveKey('table');
        $interaction->handleChar('a');
        expect($ui->menu['target']['id'])->toBe($state->processes[0]['id']);
    })->with(['dashboard', 'processes', 'nodes', 'instances']);

    it('keeps every labeled Node field reachable by keyboard and wheel without actions', function (int $nodes): void {
        $state = tui_test_state(nodeMetrics: new FakeNodeMetricsSource([
            'cores' => [0.1, 0.2], 'mem' => [1.0, 8.0], 'swap' => [0.0, 2.0],
            'uptime' => '1d 2h 3m', 'disks' => [['/', 10.0, 80.0]],
        ]));
        $state->nodes[0]['name'] = 'production-東京-worker';
        if ($nodes === 2) {
            $state->nodes[] = [...$state->nodes[0], 'id' => 2, 'name' => 'secondary-worker'];
        }
        $ui = new UiState;
        $screen = render_top_screen($ui, $state, columns: 80, rows: 24);
        $ui->focus = 'node-summary';
        $send = fn (): never => throw new RuntimeException('Read-only fields must not send a request.');
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);
        expect($ui->drawn['node-summary'])->toHaveKey('textLines')->not->toHaveKey('ids');
        $seen = $screen;
        foreach (range(1, 30) as $_) {
            $interaction->handleKey(KeyCode::Down);
            $seen .= render_top_screen($ui, $state, columns: 80, rows: 24);
        }
        expect($seen)->toMatch('/Name: production-東\s*京\s*-worker/u')->toContain('Status: active', 'CPU:', 'Mem:', 'Disk:', 'Uptime: 1d 2h 3m');
        if ($nodes === 2) {
            expect($seen)->toContain('Name: secondary-worker');
        }
        $interaction->handleChar('a');
        $interaction->handleKey(KeyCode::Enter);
        expect($ui->menu)->toBeNull()->and($ui->page())->toBeNull();
        $area = $ui->drawn['node-summary']['area'];
        $offset = $ui->selected['node-summary'];
        $interaction->handleMouse(MouseEvent::new(MouseEventKind::ScrollUp, MouseButton::Left, $area->left() + 3, $area->top() + 1, 0));
        expect($ui->selected['node-summary'])->toBe($offset - 1);
        render_top_screen($ui, $state, columns: 160, rows: 40);
        expect($ui->drawn)->not->toHaveKey('node-summary')->and($ui->selected['node-summary'])->toBe(0);
    })->with([1, 2]);

    it('preserves a selection through refusal and a transient empty frame', function (): void {
        $state = tui_test_state();
        $state->processes[] = [...$state->processes[0], 'id' => 2, 'name' => 'worker-東京-production'];
        $ui = new UiState;
        $ui->goTo('processes');
        $ui->focus = 'list';
        $ui->selected['list'] = 1;
        render_top_screen($ui, $state, columns: 140);
        render_top_screen($ui, $state, columns: 80);
        $rows = $state->processes;
        $state->processes = [];
        render_top_screen($ui, $state, columns: 80);
        $send = fn (): never => throw new RuntimeException('No request expected.');
        $interaction = new Interaction($state, $ui, new ActionRunner($send), $send);
        $interaction->handleKey(KeyCode::Down);
        $interaction->handleChar('a');
        expect($ui->selected['list'])->toBe(1)->and($ui->menu)->toBeNull();
        $state->processes = $rows;
        render_top_screen($ui, $state, columns: 140);
        $interaction->handleChar('a');
        expect($ui->menu['target']['id'])->toBe(2)->and($ui->menu['title'])->toBe('worker-東京-production');
    });

    it('refuses an infeasible Dashboard height without actionable geometry', function (): void {
        $ui = new UiState;
        render_top_screen($ui, columns: 80, rows: 24);
        expect(render_top_screen($ui, columns: 80, rows: 12))->toContain('Dashboard needs at least 18 rows.');
        expect($ui->drawn)->toBe([])->and($ui->paneOrder)->toBe([]);
        expect(render_top_screen($ui, columns: 80, rows: 24))->toContain('Needs attention');
    });
});
